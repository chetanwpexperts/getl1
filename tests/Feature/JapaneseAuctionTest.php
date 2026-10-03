<?php

namespace Tests\Feature;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use App\Services\Auction\AuctionControl;
use App\Services\Auction\AuctionService;
use App\Services\Auction\Japanese;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Japanese (falling-price) auction: the round price drops on a fixed clock; suppliers accept
 * each round to stay in; last one standing wins.
 */
class JapaneseAuctionTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Rfq $rfq;
    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC');
        $this->travelTo($this->t0);

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators', 'C' => 'Gamma Boxes'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Corrugated boxes',
            'quote_deadline' => $this->t0->copy()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 10000, 'unit' => 'pcs']],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $this->rfq = $rfq->fresh();

        // Sealed quotes: A 1,00,000 (first), B 1,02,000, C 1,05,000.
        foreach (['A' => 100000, 'B' => 102000, 'C' => 105000] as $k => $total) {
            Quote::create(['rfq_id' => $this->rfq->id, 'supplier_org_id' => $this->s[$k][0]->id, 'submitted_by' => $this->s[$k][1]->id,
                'total' => $total, 'valid_till' => $this->t0->copy()->addDays(30)->toDateString(), 'submitted_at' => $this->t0->copy()->addMinutes(10)]);
            $this->travel(1)->minutes();
        }
        $this->travelTo($this->t0->copy()->addHours(4));
    }

    /** Opening 1,00,000, drop 1,000 a round, 60-second rounds, floor 5% below (95,000 → 6 rounds). */
    private function rules(array $o = []): array
    {
        return array_merge([
            'format' => 'japanese',
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'opening_price' => '100000',
            'min_decrement_type' => 'amount',
            'min_decrement_value' => '1000',
            'round_seconds' => 60,
            'max_decrement_pct' => '5',
        ], $o);
    }

    private function live(array $o = []): Auction
    {
        $a = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules($o));
        app(CurrentOrganization::class)->set(null);
        $this->travelTo($a->starts_at->copy()->addSeconds(1));

        return $a;
    }

    private function inRound(Auction $a, int $round, int $second = 5): void
    {
        $this->travelTo($a->starts_at->copy()->addSeconds(($round - 1) * 60 + $second));
    }

    private function accept(string $k, Auction $a, int $round, ?float $price = null): \Illuminate\Testing\TestResponse
    {
        $price ??= Japanese::price($this->fresh($a), $round);
        $this->travel(1)->seconds(); // acceptances a moment apart, as in real life

        return $this->actingAs($this->s[$k][1])->postJson(route('supplier.auctions.bid', $a->id), [
            'amount' => number_format($price, 2, '.', ''), 'round' => $round, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function fresh(Auction $a): Auction
    {
        return Auction::withoutGlobalScopes()->findOrFail($a->id);
    }

    public function test_schedule_sets_rounds_and_latest_end(): void
    {
        $this->actingAs($this->buyerUser)->get(route('buyer.auctions.create', $this->rfq->id))->assertOk()
            ->assertSee('Japanese auction')->assertSee('Opening price');

        // Through the real form: only the Japanese fields are needed.
        $this->actingAs($this->buyerUser)->post(route('buyer.auctions.store', $this->rfq->id), $this->rules(['opening_price' => '10000']))
            ->assertSessionHasErrors('opening_price');

        // Opening above the best sealed quote is refused: the result could end above a price we already have.
        try {
            app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules(['opening_price' => '100001']));
            $this->fail('Accepted an opening price above the best sealed quote');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_price', $e->errors());
        }

        // Opening price far from the sealed quotes is refused (typo guard).
        try {
            app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules(['opening_price' => '10000']));
            $this->fail('Accepted a typo opening price');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_price', $e->errors());
        }

        $a = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, $this->rules());
        $this->assertTrue($a->isJapanese());
        $this->assertEquals(100000, (float) $a->opening_price);
        $this->assertEquals(100000, (float) $a->start_price, 'Savings are measured against the best sealed quote');
        $this->assertSame(6, Japanese::maxRounds($a));           // 1,00,000 → 95,000 in 1,000 steps
        $this->assertEquals(95000, Japanese::floor($a));
        $this->assertEquals(97000, Japanese::price($a, 4));
        $this->assertEquals(95000, Japanese::price($a, 9), 'Never below the floor');
        $this->assertTrue($a->ends_at->equalTo($a->starts_at->copy()->addSeconds(360)));
    }

    public function test_last_supplier_standing_wins_at_their_round_price(): void
    {
        $a = $this->live();

        // Round 1 (1,00,000): all three accept.
        foreach (['B', 'A', 'C'] as $k) {
            $this->accept($k, $a, 1)->assertOk();
        }
        $this->accept('A', $a, 1)->assertStatus(422); // once per round

        // Round 2 (99,000): A and B accept; C drops out.
        $this->inRound($a, 2);
        $this->accept('A', $a, 2)->assertOk();
        $this->accept('B', $a, 2)->assertOk();
        $this->actingAs($this->s['C'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk()
            ->assertJsonPath('my_status', 'in')->assertJsonPath('round', 2)->assertJsonPath('still_in', 3);

        // Round 3 (98,000): C is out; only B accepts.
        $this->inRound($a, 3);
        $this->accept('C', $a, 3)->assertStatus(422)->assertJsonPath('errors.amount.0', fn ($m) => str_contains($m, 'dropped out'));
        $this->actingAs($this->s['C'][1])->getJson(route('supplier.auctions.state', $a->id))->assertJsonPath('my_status', 'out');
        $this->accept('B', $a, 3)->assertOk();
        $this->assertSame(AuctionStatus::Live, \App\Services\Auction\Standings::effectiveStatus($this->fresh($a)));

        // Round 3 ends with one acceptance: B wins at 98,000.
        $this->inRound($a, 4, 1);
        app(AuctionService::class)->tick();
        $f = $this->fresh($a);
        $this->assertSame(AuctionStatus::Closed, $f->status);
        $this->assertEquals(98000, (float) $f->current_l1);
        $this->assertSame($this->s['B'][0]->id, $f->current_l1_supplier_org_id);
        $this->assertTrue($f->ends_at->equalTo($a->starts_at->copy()->addSeconds(180)));

        $standings = \App\Services\Auction\Standings::for($f);
        $this->assertSame([$this->s['B'][0]->id, $this->s['A'][0]->id, $this->s['C'][0]->id], $standings->pluck('supplier_org_id')->all());
        $this->assertEquals([98000, 99000, 100000], $standings->pluck('amount')->all());

        // Nothing is accepted after the end.
        $this->accept('B', $a, 4)->assertStatus(422);
        $this->actingAs($this->s['A'][1])->getJson(route('supplier.auctions.state', $a->id))->assertJsonPath('my_rank', 2)->assertJsonPath('my_status', 'finished');
    }

    public function test_nobody_accepts_so_the_previous_round_decides_by_time(): void
    {
        $a = $this->live();
        $this->accept('C', $a, 1)->assertOk();   // first to accept
        $this->accept('A', $a, 1)->assertOk();
        $this->inRound($a, 2);                   // nobody accepts 99,000
        $this->inRound($a, 3, 1);
        app(AuctionService::class)->tick();

        $f = $this->fresh($a);
        $this->assertSame(AuctionStatus::Closed, $f->status);
        $this->assertSame($this->s['C'][0]->id, $f->current_l1_supplier_org_id, 'Tie at the last accepted price: the earlier acceptance wins');
        $this->assertEquals(100000, (float) $f->current_l1);
        $this->assertTrue($f->ends_at->equalTo($a->starts_at->copy()->addSeconds(120)));
    }

    public function test_floor_round_ends_the_auction(): void
    {
        $a = $this->live();
        for ($r = 1; $r <= 6; $r++) {
            $this->inRound($a, $r);
            $this->accept('A', $a, $r)->assertOk();
            $this->accept('B', $a, $r)->assertOk();
        }
        $this->inRound($a, 7, 1);
        app(AuctionService::class)->tick();
        $f = $this->fresh($a);
        $this->assertSame(AuctionStatus::Closed, $f->status);
        $this->assertEquals(95000, (float) $f->current_l1);
        $this->assertSame($this->s['A'][0]->id, $f->current_l1_supplier_org_id, 'At the floor, the first to accept wins');
    }

    public function test_stale_round_or_price_is_refused(): void
    {
        $a = $this->live();
        $this->accept('A', $a, 1)->assertOk();
        $this->accept('B', $a, 1)->assertOk();
        $this->inRound($a, 2);
        // Clicked "accept" while round 1 was on screen.
        $this->accept('A', $a, 1, 100000)->assertStatus(422)->assertJsonPath('errors.amount.0', fn ($m) => str_contains($m, 'moved on'));
        // Right round, wrong price (tampered).
        $this->accept('A', $a, 2, 50000)->assertStatus(422);
        $this->assertSame(2, Bid::where('auction_id', $a->id)->where('kind', 'live')->count());
        // Not a participant at all.
        [, $stranger] = $this->supplier('Stranger');
        $this->actingAs($stranger)->postJson(route('supplier.auctions.bid', $a->id), ['amount' => '99000.00', 'round' => 2, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_pause_stops_the_round_clock(): void
    {
        $a = $this->live();
        $this->accept('A', $a, 1)->assertOk();
        $this->accept('B', $a, 1)->assertOk();
        $this->inRound($a, 2, 30);               // 30 s into round 2
        $control = app(AuctionControl::class);
        $admin = $this->admin();
        $control->pause($this->fresh($a), $admin, 'Socket server restart');
        $this->travel(10)->minutes();
        $this->assertSame(AuctionStatus::Live, \App\Services\Auction\Standings::effectiveStatus($this->fresh($a)), 'Paused: rounds do not run out');
        $control->resume($this->fresh($a), $admin);

        // Still round 2 with ~30 s left; accepting works.
        $f = $this->fresh($a);
        $this->assertSame(2, Japanese::state($f)['round']);
        $this->accept('A', $a, 2)->assertOk();

        try {
            $control->addTime($f, $admin, 2, 'more time');
            $this->fail('Added time to a Japanese auction');
        } catch (ValidationException) {
        }
    }

    public function test_pages_and_award_use_the_round_result(): void
    {
        $a = $this->live();
        $this->accept('A', $a, 1)->assertOk();
        $this->accept('B', $a, 1)->assertOk();

        $this->actingAs($this->buyerUser)->getJson(route('buyer.auctions.state', $a->id))->assertOk()
            ->assertJsonPath('format', 'japanese')->assertJsonPath('round', 1)->assertJsonPath('accepted_count', 2)
            ->assertJsonPath('bidders.0.supplier', 'Alpha Packaging')->assertJsonPath('bidders.0.accepted', true);
        $sup = $this->actingAs($this->s['C'][1])->getJson(route('supplier.auctions.state', $a->id))->assertOk();
        $this->assertStringNotContainsString('Alpha', $sup->getContent());
        $this->actingAs($this->buyerUser)->get(route('buyer.auctions.show', $a->id))->assertOk()->assertSee('Japanese auction');
        $this->actingAs($this->s['A'][1])->get(route('supplier.auctions.show', $a->id))->assertOk()->assertSee('Round price');

        $this->inRound($a, 2);
        $this->accept('B', $a, 2)->assertOk();
        $this->inRound($a, 3, 1);
        app(AuctionService::class)->tick();

        // Award candidates come from the round ranking: B at 99,000 is L1.
        $c = app(\App\Services\AwardService::class)->candidates($this->rfq->fresh());
        $this->assertSame($this->s['B'][0]->id, $c->first()['supplier']->id);
        $this->assertEquals(99000, $c->first()['basic']);
    }
}
