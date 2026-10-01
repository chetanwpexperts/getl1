@php
    $inr = fn ($v) => \App\Support\Money::inr($v);
    $gst = (float) $p->gst_amount;
    $addr = collect([$billed['address'] ?? null, trim(collect([$billed['city'] ?? null, $billed['state'] ?? null])->filter()->implode(', ').(! empty($billed['pincode']) ? ' '.$billed['pincode'] : ''))])->filter()->implode("\n");
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $taxInvoice ? 'Tax invoice' : 'Payment receipt' }} {{ $p->invoice_number }}</title>
<style>
    @page { margin: 32px 36px 48px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 10px; color: #0f172a; line-height: 1.45; }
    h1 { font-size: 17px; margin: 0; letter-spacing: 1px; }
    .muted { color: #64748b; }
    table { width: 100%; border-collapse: collapse; }
    .box { border: 1px solid #cbd5e1; padding: 8px 10px; vertical-align: top; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; margin-bottom: 3px; }
    .items th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 6px; font-size: 8px; text-transform: uppercase; text-align: left; }
    .items td { border: 1px solid #cbd5e1; padding: 6px; }
    .items th.r { text-align: right; }
    .r { text-align: right; }
    .totals td { padding: 3px 6px; }
    .grand td { border-top: 1.5px solid #0f172a; font-weight: bold; font-size: 11px; padding-top: 5px; }
    .paid { display: inline-block; border: 1.5px solid #047857; color: #047857; padding: 2px 8px; font-weight: bold; letter-spacing: 1px; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
<div class="footer">{{ $seller['name'] }} · {{ $seller['email'] }} · This is a computer-generated document and needs no signature.</div>

<table>
    <tr>
        <td style="width:60%; vertical-align:top">
            <div style="font-size:18px; font-weight:bold">Get<span style="color:#047857">L1</span></div>
            <div>{{ $seller['name'] }}</div>
            <div class="muted">{{ $seller['address'] }}</div>
            @if ($taxInvoice && $seller['gstin'])<div>GSTIN: <strong>{{ $seller['gstin'] }}</strong></div>@endif
        </td>
        <td style="width:40%; text-align:right; vertical-align:top">
            <h1>{{ $taxInvoice ? 'TAX INVOICE' : 'PAYMENT RECEIPT' }}</h1>
            <table style="margin-top:6px">
                <tr><td class="muted r">Number</td><td class="r"><strong>{{ $p->invoice_number }}</strong></td></tr>
                <tr><td class="muted r">Date</td><td class="r">{{ $p->paid_at?->ist()->format('d M Y') }}</td></tr>
                <tr><td class="muted r">Payment ref.</td><td class="r">{{ $p->razorpay_payment_id }}</td></tr>
            </table>
            <div style="margin-top:6px"><span class="paid">PAID</span></div>
        </td>
    </tr>
</table>

<table style="margin-top:16px">
    <tr>
        <td class="box">
            <div class="label">Billed to</div>
            <div style="font-size:11px; font-weight:bold">{{ $billed['name'] ?? '' }}</div>
            <div style="white-space: pre-line">{{ $addr }}</div>
            @if (! empty($billed['gstin']))<div>GSTIN: <strong>{{ $billed['gstin'] }}</strong></div>@endif
        </td>
    </tr>
</table>

<table class="items" style="margin-top:14px">
    <thead><tr><th>Description</th>@if ($taxInvoice && $seller['sac'])<th style="width:12%">SAC</th>@endif<th class="r" style="width:20%">Amount</th></tr></thead>
    <tbody>
        <tr>
            <td>
                <strong>{{ $p->description() }}</strong>
                @if ($p->period_start && $p->period_end)
                    <br><span class="muted">Service period {{ $p->period_start->ist()->format('d M Y') }} to {{ $p->period_end->ist()->format('d M Y') }}</span>
                @endif
            </td>
            @if ($taxInvoice && $seller['sac'])<td>{{ $seller['sac'] }}</td>@endif
            <td class="r">{{ $inr($p->amount) }}</td>
        </tr>
    </tbody>
</table>

<table style="margin-top:8px">
    <tr>
        <td style="width:55%"></td>
        <td style="width:45%">
            <table class="totals">
                <tr><td>{{ $taxInvoice ? 'Taxable value' : 'Amount' }}</td><td class="r">{{ $inr($p->amount) }}</td></tr>
                @if ($taxInvoice)
                    @if ($intraState)
                        <tr><td>CGST {{ rtrim(rtrim(number_format($p->gst_rate / 2, 2), '0'), '.') }}%</td><td class="r">{{ $inr(round($gst / 2, 2)) }}</td></tr>
                        <tr><td>SGST {{ rtrim(rtrim(number_format($p->gst_rate / 2, 2), '0'), '.') }}%</td><td class="r">{{ $inr($gst - round($gst / 2, 2)) }}</td></tr>
                    @else
                        <tr><td>IGST {{ rtrim(rtrim(number_format($p->gst_rate, 2), '0'), '.') }}%</td><td class="r">{{ $inr($gst) }}</td></tr>
                    @endif
                @endif
                <tr class="grand"><td>Total paid</td><td class="r">{{ $inr($p->total) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<p style="margin-top:10px"><span class="label">Amount in words</span><br><strong>{{ \App\Support\Money::words($p->total) }}</strong></p>
<p class="muted" style="margin-top:18px">Paid online via Razorpay. Thank you for choosing GetL1.</p>
</body>
</html>
