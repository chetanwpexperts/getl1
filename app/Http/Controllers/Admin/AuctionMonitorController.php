<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuctionStatus;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuditLog;
use App\Models\Bid;
use App\Models\BidRejection;
use App\Models\Organization;
use App\Models\Rfq;
use App\Services\Auction\AuctionControl;
use App\Services\Auction\Standings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * GetL1 operations: every live and upcoming auction across all companies, with the
 * emergency controls (pause, resume, add time, cancel). Staff see, but never change, bids.
 */
class AuctionMonitorController extends Controller
{
    public function __construct(private AuctionControl $control) {}

    public function index(): View
    {
        return view('admin.auctions.index', $this->board() + ['live' => $this->boardVersion()]);
    }

    public function indexLive(): JsonResponse
    {
        return response()->json($this->boardVersion() + ['server_time' => now()->getTimestampMs()])->header('Cache-Control', 'no-store');
    }

    public function show(int $auction): View
    {
        $a = $this->find($auction);
        $standings = Standings::for($a);
        $orgs = Organization::withTrashed()->whereIn('id', $standings->pluck('supplier_org_id')->push($a->organization_id))->get()->keyBy('id');

        $bids = Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $rejections = BidRejection::where('auction_id', $a->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $feed = $bids->map(fn ($b) => ['at' => $b->created_at, 'ok' => true, 'supplier' => $b->supplier_org_id, 'amount' => (float) $b->amount,
            'text' => 'Accepted'.($b->rank_at_submit ? ' · L'.$b->rank_at_submit : ''), 'ip' => $b->ip])
            ->concat($rejections->map(fn ($r) => ['at' => $r->created_at, 'ok' => false, 'supplier' => $r->supplier_org_id, 'amount' => $r->amount_input,
                'text' => $r->message, 'ip' => $r->ip]))
            ->sortByDesc(fn ($r) => $r['at']->format('U.u'))->take(200)->values();

        return view('admin.auctions.show', [
            'a' => $a,
            'rfq' => Rfq::withoutGlobalScopes()->find($a->rfq_id),
            'standings' => $standings,
            'orgs' => $orgs,
            'feed' => $feed,
            'velocity' => $this->velocity($a),
            'actions' => AuditLog::with('user:id,name,email')->where('auditable_type', 'auction')->where('auditable_id', $a->id)->latest('id')->limit(30)->get(),
            'live' => $this->auctionVersion($a),
            'addMinutes' => AuctionControl::ADD_MINUTES,
        ]);
    }

    public function showLive(int $auction): JsonResponse
    {
        return response()->json($this->auctionVersion($this->find($auction)) + ['server_time' => now()->getTimestampMs()])->header('Cache-Control', 'no-store');
    }

    public function pause(Request $request, int $auction): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $this->control->pause($this->find($auction), $request->user(), $data['reason']);

        return back()->with('status', 'Auction paused. The clock is stopped and the buyer and suppliers have been told.');
    }

    public function resume(Request $request, int $auction): RedirectResponse
    {
        $a = $this->control->resume($this->find($auction), $request->user());

        return back()->with('status', 'Auction resumed. It now ends at '.$a->ends_at->ist()->format('h:i:s A').' IST.');
    }

    public function addTime(Request $request, int $auction): RedirectResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:200']]);
        $a = $this->control->addTime($this->find($auction), $request->user(), (int) $data['minutes'], $data['reason']);

        return back()->with('status', "Added {$data['minutes']} minutes. It now ends at ".$a->ends_at->ist()->format('h:i:s A').' IST.');
    }

    public function cancel(Request $request, int $auction): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:200'],
            'confirm' => ['required', 'in:CANCEL'],
        ], ['confirm.in' => 'Type CANCEL to confirm.']);
        $this->control->cancel($this->find($auction), $request->user(), $data['reason']);

        return back()->with('status', 'Auction cancelled. The buyer and suppliers have been told why.');
    }

    private function find(int $id): Auction
    {
        return Auction::withoutGlobalScopes()->findOrFail($id);
    }

    /** @return array{running: Collection, upcoming: Collection, recent: Collection, orgs: Collection, rfqs: Collection, stats: array} */
    private function board(): array
    {
        $base = fn () => Auction::withoutGlobalScopes();
        $running = $base()->where('status', AuctionStatus::Live->value)->orderBy('ends_at')->get();
        // Scheduled ones whose start has passed are live by the clock even before the next tick.
        $upcoming = $base()->where('status', AuctionStatus::Scheduled->value)->where('starts_at', '<=', now()->addDay())->orderBy('starts_at')->get();
        $recent = $base()->whereIn('status', [AuctionStatus::Closed->value, AuctionStatus::Cancelled->value])
            ->where('updated_at', '>=', now()->subHours(6))->latest('updated_at')->limit(15)->get();
        $all = $running->concat($upcoming)->concat($recent);

        $since = now()->subMinutes(5);
        $activity = Bid::whereIn('auction_id', $running->pluck('id'))->where('kind', Bid::KIND_LIVE)
            ->selectRaw('auction_id, count(*) as recent_bids, count(distinct supplier_org_id) as active')
            ->where('created_at', '>=', $since)->groupBy('auction_id')->get()->keyBy('auction_id');
        $participants = Bid::whereIn('auction_id', $all->pluck('id'))->selectRaw('auction_id, count(distinct supplier_org_id) as n')
            ->groupBy('auction_id')->pluck('n', 'auction_id');
        $rejected = BidRejection::whereIn('auction_id', $running->pluck('id'))->where('created_at', '>=', $since)
            ->selectRaw('auction_id, count(*) as n')->groupBy('auction_id')->pluck('n', 'auction_id');

        return [
            'running' => $running, 'upcoming' => $upcoming, 'recent' => $recent,
            'orgs' => Organization::withTrashed()->whereIn('id', $all->pluck('organization_id'))->pluck('name', 'id'),
            'rfqs' => Rfq::withoutGlobalScopes()->whereIn('id', $all->pluck('rfq_id'))->pluck('title', 'id'),
            'activity' => $activity, 'participants' => $participants, 'rejected' => $rejected,
            'stats' => [
                'live' => $running->count(),
                'paused' => $running->whereNotNull('paused_at')->count(),
                'bids_5m' => (int) $activity->sum('recent_bids'),
                'rejected_5m' => (int) $rejected->sum(),
                'today' => $base()->where('starts_at', '>=', now()->setTimezone(config('app.display_timezone'))->startOfDay()->utc())
                    ->where('starts_at', '<', now()->setTimezone(config('app.display_timezone'))->endOfDay()->utc())->count(),
            ],
        ];
    }

    /** Changes whenever any bid, refusal or auction changes; refresh_at = the next start or end. */
    private function boardVersion(): array
    {
        $a = Auction::withoutGlobalScopes();
        $next = collect([
            (clone $a)->where('status', AuctionStatus::Scheduled->value)->where('starts_at', '>', now())->min('starts_at'),
            (clone $a)->where('status', AuctionStatus::Live->value)->whereNull('paused_at')->where('ends_at', '>', now())->min('ends_at'),
        ])->filter()->map(fn ($t) => \Illuminate\Support\Carbon::parse($t)->getTimestampMs())->min();

        return [
            'v' => sha1(implode('|', [Bid::max('id'), BidRejection::max('id'), (clone $a)->max('updated_at'), (clone $a)->count()])),
            'refresh_at' => $next,
        ];
    }

    private function auctionVersion(Auction $a): array
    {
        return [
            'v' => sha1(implode('|', [$a->updated_at?->format('U.u'), $a->bid_count, Bid::where('auction_id', $a->id)->max('id'),
                BidRejection::where('auction_id', $a->id)->max('id')])),
            'refresh_at' => match (true) {
                $a->status === AuctionStatus::Scheduled => $a->starts_at->getTimestampMs(),
                $a->status === AuctionStatus::Live && $a->paused_at === null => $a->ends_at->getTimestampMs(),
                default => null,
            },
        ];
    }

    /** Live bids per minute over the last 15 minutes (oldest first). */
    private function velocity(Auction $a): array
    {
        $from = now()->subMinutes(14)->startOfMinute();
        $counts = Bid::where('auction_id', $a->id)->where('kind', Bid::KIND_LIVE)->where('created_at', '>=', $from)
            ->get(['created_at'])->countBy(fn ($b) => $b->created_at->copy()->startOfMinute()->getTimestamp());
        $out = [];
        for ($i = 0; $i < 15; $i++) {
            $t = $from->copy()->addMinutes($i);
            $out[] = ['t' => $t, 'n' => (int) ($counts[$t->getTimestamp()] ?? 0)];
        }

        return $out;
    }
}
