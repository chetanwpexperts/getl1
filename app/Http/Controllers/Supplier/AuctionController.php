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
        ]);

        $auction = $this->findParticipating($auction);
        $result = $bids->place($auction, $this->current->get(), $request->user(), $data['amount'], $data['idempotency_key'],
            $request->ip(), $request->userAgent(), isset($data['item']) ? (int) $data['item'] : null);

        return response()->json([
            'ok' => true,
            'duplicate' => $result['duplicate'],
            'extended' => $result['extended'],
            'state' => AuctionState::forSupplier($auction->fresh(), $this->current->id()),
        ]);
    }

    private function findParticipating(int $id): Auction
    {
        $participates = Bid::where('auction_id', $id)->where('supplier_org_id', $this->current->id())->exists();
        abort_unless($participates, 404);

        return Auction::withoutGlobalScopes()->findOrFail($id);
    }
}
