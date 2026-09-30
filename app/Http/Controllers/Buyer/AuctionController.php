<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\RfqStatus;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Quote;
use App\Models\Rfq;
use App\Services\Auction\AuctionService;
use App\Services\Auction\AuctionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Buyer side of live auctions. Auction and Rfq are scoped to the buyer's company (404 otherwise). */
class AuctionController extends Controller
{
    public function create(int $rfq): View|RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        if (! $rfq->quotesAreUnsealed() || $rfq->status !== RfqStatus::Published) {
            return redirect()->route('buyer.rfqs.show', $rfq->id)->withErrors(['rfq' => 'An auction can be scheduled once the quote deadline has passed.']);
        }

        $quotes = Quote::with('supplier')->where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->orderBy('total')->get();

        return view('buyer.auctions.create', [
            'rfq' => $rfq,
            'quotes' => $quotes,
            'defaultStart' => now()->addMinutes(20)->ceilMinute(5)->ist()->format('Y-m-d\TH:i'),
            'minParticipants' => AuctionService::MIN_PARTICIPANTS,
        ]);
    }

    public function store(Request $request, int $rfq, AuctionService $auctions): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $data = $request->validate(AuctionService::rules());
        $auction = $auctions->schedule($rfq, $request->user(), $data);

        return redirect()->route('buyer.auctions.show', $auction->id)
            ->with('status', 'Auction scheduled. Participating suppliers have been emailed the start time.');
    }

    public function show(int $auction): View
    {
        $auction = Auction::with('rfq')->findOrFail($auction);

        return view('buyer.auctions.show', [
            'auction' => $auction,
            'rfq' => $auction->rfq,
            'state' => AuctionState::forBuyer($auction),
            // For "send the room link on WhatsApp" while scheduled or live.
            'participants' => \App\Models\RfqInvite::with(['listEntry', 'supplier'])
                ->where('rfq_id', $auction->rfq_id)
                ->whereIn('supplier_org_id', \App\Models\Bid::where('auction_id', $auction->id)->distinct()->select('supplier_org_id'))
                ->get(),
        ]);
    }

    public function state(int $auction): JsonResponse
    {
        return response()->json(AuctionState::forBuyer(Auction::findOrFail($auction)))
            ->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request, int $auction, AuctionService $auctions): RedirectResponse
    {
        $auction = Auction::findOrFail($auction);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $auctions->cancel($auction, $request->user(), $data['reason']);

        return redirect()->route('buyer.rfqs.show', $auction->rfq_id)->with('status', 'Auction cancelled. You can schedule a new one.');
    }
}
