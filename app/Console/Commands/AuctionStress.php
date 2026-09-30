<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Enums\RfqStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use App\Services\Auction\AuctionService;
use App\Services\Auction\BidService;
use App\Services\Auction\Standings;
use App\Services\OrganizationService;
use App\Services\RfqService;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hammers one live auction with many suppliers bidding at the same moment (separate PHP
 * processes against the real database), then checks the results are consistent:
 * no lost or double bids, L1 correct, every accepted bid obeyed the rules.
 *
 * Staging/local only. Uses its own "Stress Test" companies (reused on every run).
 */
class AuctionStress extends Command
{
    protected $signature = 'getl1:auction-stress
        {--suppliers=20 : Parallel bidders}
        {--seconds=15 : How long each bidder keeps bidding}
        {--worker : (internal) run as one bidder}
        {--auction=} {--supplier=} {--start-at=}';

    protected $description = 'Parallel bidding test for live auctions (never runs in production)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return 1;
        }

        return $this->option('worker') ? $this->worker() : $this->coordinator();
    }

    private function coordinator(): int
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->error('Needs MySQL: SQLite has no row locks, so the test would prove nothing.');

            return 1;
        }

        $n = max(2, min(100, (int) $this->option('suppliers')));
        $secs = max(3, min(120, (int) $this->option('seconds')));
        Mail::fake(); // test companies have no real inboxes

        $this->info("Setting up an auction with {$n} bidders…");
        $auction = $this->setUp($n);
        $suppliers = Bid::where('auction_id', $auction->id)->distinct()->pluck('supplier_org_id');

        $startAt = microtime(true) + 3; // everyone fires at the same instant
        $this->info("Bidding for {$secs}s from {$n} processes…");

        $results = Process::pool(function (Pool $pool) use ($suppliers, $auction, $secs, $startAt) {
            foreach ($suppliers as $orgId) {
                $pool->path(base_path())->timeout($secs + 60)->command([
                    PHP_BINARY, 'artisan', 'getl1:auction-stress', '--worker',
                    "--auction={$auction->id}", "--supplier={$orgId}", "--seconds={$secs}", "--start-at={$startAt}",
                ]);
            }
        })->start()->wait();

        $totals = ['accepted' => 0, 'rejected' => 0, 'duplicates_ok' => 0, 'errors' => 0];
        $reports = $results->collect();
        if ($reports->count() !== $suppliers->count()) {
            $totals['errors']++;
            $this->warn("Expected {$suppliers->count()} worker reports, got {$reports->count()}.");
        }
        foreach ($reports as $r) {
            $line = collect(explode("\n", trim($r->output())))->last();
            $data = json_decode((string) $line, true);
            if (! $r->successful() || ! is_array($data)) {
                $totals['errors']++;
                $this->warn('Worker failed: '.Str::limit(trim($r->errorOutput() ?: $r->output()), 300));

                continue;
            }
            foreach (['accepted', 'rejected', 'duplicates_ok'] as $k) {
                $totals[$k] += $data[$k];
            }
            $totals['errors'] += $data['errors'];
        }

        $this->table(['Accepted bids', 'Rejected (rule)', 'Retries deduplicated', 'Errors'], [array_values($totals)]);

        $passed = $this->verify($auction->fresh(), $totals);

        // Close the test auction now rather than leaving it running.
        $auction->forceFill(['ends_at' => now()->subSecond()])->save();
        app(AuctionService::class)->tick();

        return $passed ? 0 : 1;
    }

    private function worker(): int
    {
        $auction = Auction::withoutGlobalScopes()->findOrFail((int) $this->option('auction'));
        $org = Organization::findOrFail((int) $this->option('supplier'));
        $user = $org->users()->firstOrFail();
        $bids = app(BidService::class);

        $wait = (float) $this->option('start-at') - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }

        $until = microtime(true) + (int) $this->option('seconds');
        $stats = ['accepted' => 0, 'rejected' => 0, 'duplicates_ok' => 0, 'errors' => 0];

        while (microtime(true) < $until) {
            $a = Auction::withoutGlobalScopes()->find($auction->id);
            $own = (float) Bid::where('auction_id', $a->id)->where('supplier_org_id', $org->id)
                ->orderByDesc('created_at')->orderByDesc('id')->value('amount');
            $target = Standings::maxNextBid($a, $own) - random_int(0, (int) ($own * 0.004));
            $target = max($target, Standings::floor($a) + random_int(0, 50));
            $key = (string) Str::uuid();

            try {
                $r = $bids->place($a, $org, $user, number_format($target, 2, '.', ''), $key, '127.0.0.1', 'stress-test');
                $stats['accepted']++;
                // Every few bids, replay the same request like a network retry would.
                if (random_int(1, 4) === 1) {
                    $again = $bids->place($a, $org, $user, number_format($target, 2, '.', ''), $key, '127.0.0.1', 'stress-test');
                    if ($again['duplicate'] && $again['bid']->id === $r['bid']->id) {
                        $stats['duplicates_ok']++;
                    } else {
                        $stats['errors']++;
                    }
                }
            } catch (ValidationException) {
                $stats['rejected']++; // e.g. undercut by others past the typo floor, or too fast
            } catch (\Throwable $e) {
                $stats['errors']++;
                fwrite(STDERR, $e->getMessage()."\n");
            }

            usleep(random_int(1_000_000, 1_150_000)); // one bid per second per supplier is the rule
        }

        $this->line(json_encode($stats));

        return 0;
    }

    private function setUp(int $n): Auction
    {
        $orgs = app(OrganizationService::class);
        $user = function (string $email) {
            return User::firstOrCreate(['email' => $email], ['name' => 'Stress Test', 'password' => Hash::make(Str::random(40))]);
        };

        $buyerUser = $user('stress-buyer@getl1.test');
        $buyer = $buyerUser->organizations()->first()
            ?? $orgs->createWithOwner($buyerUser, ['name' => 'Stress Test Buyer', 'city' => 'Mohali'], OrganizationType::Buyer);

        $rfq = app(RfqService::class)->saveDraft($buyer, $buyerUser, [
            'title' => 'Stress test '.now()->format('Y-m-d H:i:s'),
            'quote_deadline' => now()->addDay()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Test item', 'qty' => 1000, 'unit' => 'pcs']],
        ]);

        for ($i = 1; $i <= $n; $i++) {
            $su = $user("stress-supplier-{$i}@getl1.test");
            $org = $su->organizations()->first()
                ?? $orgs->createWithOwner($su, ['name' => "Stress Test Supplier {$i}", 'city' => 'Ludhiana'], OrganizationType::Supplier);
            Quote::create([
                'rfq_id' => $rfq->id, 'supplier_org_id' => $org->id, 'submitted_by' => $su->id,
                'total' => 1_000_000 + random_int(0, 50_000), 'submitted_at' => now()->subMinutes($n - $i + 1),
            ]);
        }

        // Quotes are in and the deadline has passed.
        Rfq::withoutGlobalScopes()->whereKey($rfq->id)->update(['status' => RfqStatus::Published->value, 'quote_deadline' => now()->subSecond()]);

        $auction = app(AuctionService::class)->schedule(Rfq::withoutGlobalScopes()->findOrFail($rfq->id), $buyerUser, [
            'starts_at' => now()->addMinutes(10)->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'),
            'duration_min' => 10, 'min_decrement_type' => 'amount', 'min_decrement_value' => '100',
            'max_decrement_pct' => '5', 'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0,
            'visibility' => 'rank_only',
        ]);

        // Start now instead of in 10 minutes (test only).
        $auction->forceFill(['starts_at' => now()->subSecond(), 'ends_at' => now()->addMinutes(10), 'original_ends_at' => now()->addMinutes(10)])->save();

        return $auction;
    }

    private function verify(Auction $a, array $totals): bool
    {
        $ok = true;
        $check = function (bool $pass, string $label) use (&$ok) {
            $this->line(($pass ? '  <info>✓</info> ' : '  <error>✗</error> ').$label);
            $ok = $ok && $pass;
        };

        $live = Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->orderBy('created_at')->orderBy('id')->get();
        $standings = Standings::for($a);

        $check($live->count() === $totals['accepted'], "Every accepted bid is stored exactly once ({$live->count()} rows, {$totals['accepted']} accepted)");
        $check($a->bid_count === $live->count(), "Auction bid counter matches stored bids ({$a->bid_count})");
        $check($live->pluck('idempotency_key')->duplicates()->isEmpty(), 'No duplicate idempotency keys');
        $check((float) $a->current_l1 === $standings->first()['amount'] && $a->current_l1_supplier_org_id === $standings->first()['supplier_org_id'],
            'Stored L1 matches recalculated standings');

        // Each supplier's bids went strictly down by at least the minimum step.
        $all = Bid::where('auction_id', $a->id)->orderBy('created_at')->orderBy('id')->get()->groupBy('supplier_org_id');
        $stepOk = $gapOk = true;
        foreach ($all as $rows) {
            $prev = null;
            foreach ($rows as $b) {
                if ($prev) {
                    $stepOk = $stepOk && (float) $b->amount <= (float) $prev->amount - (float) $a->min_decrement_value + 0.001;
                    if ($prev->kind === Bid::KIND_LIVE) {
                        $gapOk = $gapOk && $prev->created_at->diffInMilliseconds($b->created_at, true) >= 1000;
                    }
                }
                $prev = $b;
            }
        }
        $check($stepOk, 'Every bid beat the supplier’s own previous price by the minimum step');
        $check($gapOk, 'No supplier bid twice within one second');
        $check($totals['errors'] === 0, 'No unexpected errors');

        $this->newLine();
        $this->line("Auction #{$a->id}: start ".number_format((float) $a->start_price, 2).' → L1 '.number_format((float) $a->current_l1, 2));

        return $ok;
    }
}
