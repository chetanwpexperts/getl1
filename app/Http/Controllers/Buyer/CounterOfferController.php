<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\CounterOffer;
use App\Models\Rfq;
use App\Services\CounterOfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Buyer counter-offers. The RFQ is scoped to the buyer's company (404 otherwise). */
class CounterOfferController extends Controller
{
    public function __construct(private CounterOfferService $offers) {}

    public function store(Request $request, int $rfq): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $data = $request->validate([
            'supplier_org_id' => ['required', 'integer'],
            'offered_amount' => ['required', 'string', 'max:20', 'regex:/^[\d,]+(\.\d{1,2})?$/'],
            'hours' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], ['offered_amount.regex' => 'Enter the amount in rupees, up to 2 decimals.']);

        $offer = $this->offers->offer($rfq, $request->user(), (int) $data['supplier_org_id'], $data['offered_amount'], (int) $data['hours'], $data['message'] ?? null);

        return redirect()->to(route('buyer.rfqs.show', $rfq->id).'#award')
            ->with('status', 'Counter-offer sent to '.$offer->supplier->name.'. You\'ll get an email when they answer.');
    }

    public function withdraw(Request $request, int $rfq, int $offer): RedirectResponse
    {
        $rfq = Rfq::findOrFail($rfq);
        $o = CounterOffer::where('rfq_id', $rfq->id)->where('organization_id', $rfq->organization_id)->findOrFail($offer);
        $this->offers->withdraw($o, $request->user());

        return redirect()->to(route('buyer.rfqs.show', $rfq->id).'#award')->with('status', 'Counter-offer withdrawn.');
    }
}
