@php
    $inr = fn ($v) => \App\Support\Money::inr($v);
    $rate = fn ($v) => '₹'.number_format((float) $v, fmod((float) $v * 100, 1) != 0 ? 4 : 2, '.', ',');
    $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
    $items = $award->lines['items'] ?? [];
    $roundOff = (float) ($award->lines['round_off'] ?? 0);
    $gst = (float) $award->gst_total;
    $addr = fn ($o) => collect([$o->address, trim(collect([$o->city, $o->state])->filter()->implode(', ').($o->pincode ? ' '.$o->pincode : ''))])->filter()->implode("\n");
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Purchase Order {{ $award->po_number }}</title>
<style>
    @page { margin: 28px 32px 48px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.5px; color: #0f172a; line-height: 1.4; }
    h1 { font-size: 18px; margin: 0; letter-spacing: 1px; }
    .muted { color: #64748b; }
    .brand { color: #047857; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .box { border: 1px solid #cbd5e1; padding: 8px 10px; vertical-align: top; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; margin-bottom: 3px; }
    .party { font-size: 11px; font-weight: bold; }
    .items th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 5px 6px; font-size: 8px; text-transform: uppercase; letter-spacing: .4px; text-align: left; }
    .items td { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; }
    .items th.r { text-align: right; }
    .items th.c { text-align: center; }
    .r { text-align: right; }
    .c { text-align: center; }
    .totals td { padding: 3px 6px; }
    .totals .grand td { border-top: 1.5px solid #0f172a; font-size: 11px; font-weight: bold; padding-top: 5px; }
    .words { border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; margin-top: 8px; }
    ol { margin: 4px 0 0 16px; padding: 0; }
    li { margin-bottom: 2px; }
    .sign td { vertical-align: bottom; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
<div class="footer">{{ $award->po_number }} · {{ $buyer->name }} · Generated on GetL1 on {{ $award->po_sent_at?->ist()->format('d M Y, h:i A') }} IST · This PO is valid without a physical signature.</div>

<table class="head">
    <tr>
        <td style="width:60%">
            <div class="party" style="font-size:14px">{{ $buyer->name }}</div>
            <div class="muted" style="white-space: pre-line">{{ $addr($buyer) }}</div>
            @if ($buyer->gstin)<div>GSTIN: <strong>{{ $buyer->gstin }}</strong></div>@endif
            <div class="muted">{{ collect([$buyer->phone, $buyer->email])->filter()->implode(' · ') }}</div>
        </td>
        <td style="width:40%; text-align:right">
            <h1>PURCHASE ORDER</h1>
            <table style="margin-top:6px">
                <tr><td class="muted r">PO number</td><td class="r"><strong>{{ $award->po_number }}</strong></td></tr>
                <tr><td class="muted r">PO date</td><td class="r">{{ $award->po_sent_at?->ist()->format('d M Y') }}</td></tr>
                <tr><td class="muted r">RFQ reference</td><td class="r">{{ $rfq->ref_no }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table style="margin-top:14px">
    <tr>
        <td class="box" style="width:50%">
            <div class="label">Supplier</div>
            <div class="party">{{ $supplier->name }}</div>
            <div style="white-space: pre-line">{{ $addr($supplier) }}</div>
            @if ($supplier->gstin)<div>GSTIN: <strong>{{ $supplier->gstin }}</strong></div>@endif
            <div class="muted">{{ collect([$supplier->phone, $supplier->email])->filter()->implode(' · ') }}</div>
        </td>
        <td class="box" style="width:50%">
            <div class="label">Deliver to</div>
            <div class="party">{{ $buyer->name }}</div>
            <div style="white-space: pre-line">{{ $rfq->delivery_location ?: $addr($buyer) }}</div>
            <div class="label" style="margin-top:6px">Subject</div>
            <div>{{ $rfq->title }}</div>
        </td>
    </tr>
</table>

<table class="items" style="margin-top:14px">
    <thead>
        <tr>
            <th class="c" style="width:4%">#</th>
            <th>Item</th>
            <th class="r" style="width:11%">Qty</th>
            <th class="r" style="width:11%">Rate</th>
            <th class="r" style="width:14%">Amount</th>
            <th class="r" style="width:7%">GST</th>
            <th class="r" style="width:11%">GST amount</th>
            <th style="width:12%">Needed by</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $i => $line)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td><strong>{{ $line['name'] }}</strong>@if (! empty($line['spec']))<br><span class="muted">{{ $line['spec'] }}</span>@endif</td>
                <td class="r">{{ $qty($line['qty']) }} {{ $line['unit'] }}</td>
                <td class="r">{{ $rate($line['unit_price']) }}</td>
                <td class="r">{{ $inr($line['amount']) }}</td>
                <td class="r">{{ rtrim(rtrim(number_format($line['gst_rate'], 2), '0'), '.') }}%</td>
                <td class="r">{{ $inr($line['gst']) }}</td>
                <td>{{ ! empty($line['needed_by']) ? \Illuminate\Support\Carbon::parse($line['needed_by'])->format('d-m-Y') : '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top:8px">
    <tr>
        <td style="width:55%; vertical-align:top" class="muted">
            @if ($award->source === 'auction')
                Price finalised in a live reverse auction on GetL1{{ $award->rank === 1 ? ' (lowest bid)' : '' }}.
            @else
                Price as per the supplier's sealed quotation on GetL1.
            @endif
            Supplier's quotation and bids form part of this order.
        </td>
        <td style="width:45%">
            <table class="totals">
                <tr><td>Taxable value</td><td class="r">{{ $inr($award->total) }}</td></tr>
                @if (abs($roundOff) >= 0.01)
                    <tr><td class="muted">incl. round-off</td><td class="r muted">{{ $inr($roundOff) }}</td></tr>
                @endif
                @if ($intraState)
                    <tr><td>CGST</td><td class="r">{{ $inr(round($gst / 2, 2)) }}</td></tr>
                    <tr><td>SGST</td><td class="r">{{ $inr($gst - round($gst / 2, 2)) }}</td></tr>
                @else
                    <tr><td>IGST</td><td class="r">{{ $inr($gst) }}</td></tr>
                @endif
                @if ((float) $award->freight_total > 0)
                    <tr><td>Freight</td><td class="r">{{ $inr($award->freight_total) }}</td></tr>
                @endif
                <tr class="grand"><td>Total</td><td class="r">{{ $inr($award->grand_total) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="words"><span class="label">Amount in words</span><br><strong>{{ \App\Support\Money::words($award->grand_total) }}</strong></div>

@if ($terms)
    <div style="margin-top:12px">
        <div class="label">Terms and conditions</div>
        <ol>
            @foreach ($terms as $t)<li>{{ $t }}</li>@endforeach
        </ol>
    </div>
@endif

<table class="sign" style="margin-top:28px">
    <tr>
        <td style="width:50%" class="muted">
            @if ($approver) Approved by {{ $approver }} on {{ $award->approved_at?->ist()->format('d M Y') }}.<br>@endif
            Please accept this order on GetL1 or reply to confirm.
        </td>
        <td style="width:50%; text-align:right">
            For <strong>{{ $buyer->name }}</strong><br><br><br>
            <span class="muted">Authorised signatory</span>
        </td>
    </tr>
</table>
</body>
</html>
