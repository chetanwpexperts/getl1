<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Bid;
use App\Services\Auction\AuctionState;
use App\Services\Auction\BidService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Supplier bidding console. Only participants (suppliers with a sealed quote carried into the
 * auction) can open it; everyone else gets 404.
 */
class AuctionController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function show(int $auction): View
    {
        $auction = $this->findParticipating($auction);
        $auction->load('rfq.organization');

        return view('supplier.auctions.show', [
            'auction' => $auction,
            'rfq' => $auction->rfq,
            'state' => AuctionState::forSupplier($auction, $this->current->id()),
        ]);
    }

    public function state(int $auction): JsonResponse
    {
        $auction = $this->findParticipating($auction);

        return response()->json(AuctionState::forSupplier($auction, $this->current->id()))
            ->header('Cache-Control', 'no-store');
    }

    public function bid(Request $request, int $auction, BidService $bids): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'item' => ['nullable', 'integer', 'min:1'], // item-wise auctions: the RFQ line being bid on
            'round' => ['nullable', 'integer', 'min:1', 'max:65000'], // Japanese auctions: the round being accepted
        ]);

        $auction = $this->findParticipating($auction);
        $result = $bids->place($auction, $this->current->get(), $request->user(), $data['amount'], $data['idempotency_key'],
            $request->ip(), $request->userAgent(), isset($data['round']) ? (int) $data['round'] : (isset($data['item']) ? (int) $data['item'] : null));

        return response()->json([
            'ok' => true,
            'duplicate' => $result['duplicate'],
            'extended' => $result['extended'],
            'state' => AuctionState::forSupplier($auction->fresh(), $this->current->id()),
        ]);
    }

    /**
     * Practice room: the real bidding screen against simulated competitors, run entirely in the
     * browser. Nothing is saved and no buyer is involved.
     */
    public function practice(): View
    {
        $now = now();
        $auction = new Auction([
            'format' => Auction::ENGLISH, 'bid_basis' => 'lot_total', 'min_decrement_type' => 'percent', 'min_decrement_value' => 0.5,
            'max_decrement_pct' => 10, 'extend_window_sec' => 60, 'extend_by_sec' => 60, 'max_extensions' => 3, 'visibility' => 'rank_and_l1',
            'starts_at' => $now, 'ends_at' => $now->copy()->addMinutes(5),
        ]);
        $rfq = new \App\Models\Rfq(['title' => 'Practice: Corrugated boxes 5-ply (10,000 pcs)', 'ref_no' => 'PRACTICE']);
        $rfq->setRelation('organization', new \App\Models\Organization(['name' => 'Demo Buyer Pvt Ltd']));
        $ms = $now->getTimestampMs();

        return view('supplier.auctions.show', [
            'auction' => $auction,
            'rfq' => $rfq,
            'practice' => true,
            'state' => [
                'id' => 0, 'basis' => 'lot_total', 'format' => 'english', 'status' => 'live', 'server_time' => $ms,
                'starts_at' => $ms - 1000, 'ends_at' => $ms + 5 * 60000, 'extensions_used' => 0, 'max_extensions' => 3,
                'extend_window_sec' => 60, 'extend_by_sec' => 60, 'visibility' => 'rank_and_l1', 'paused' => false,
                'paused_remaining_ms' => null, 'notice' => null, 'role' => 'supplier', 'participants' => 4,
                'my_rank' => 3, 'my_amount' => 100000.0, 'l1_amount' => 98500.0, 'max_next_bid' => 99500.0, 'min_decrement' => 500.0,
                'floor' => 88650.0, 'my_bids' => [['amount' => 100000.0, 'kind' => 'sealed', 'rank' => 3, 'at' => $ms - 3600000]],
            ],
        ]);
    }

    private function findParticipating(int $id): Auction
    {
        $participates = Bid::where('auction_id', $id)->where('supplier_org_id', $this->current->id())->exists();
        abort_unless($participates, 404);

        return Auction::withoutGlobalScopes()->findOrFail($id);
    }
}
