@extends('layouts.site')

@section('title', 'Cancellation and refund policy')
@section('description', 'How cancellations and refunds work for GetL1 plans, auction credits and AI reading packs.')

@section('content')
    @include('site._policy', ['heading' => 'Cancellation and refund policy'])
    <article class="policy mx-auto max-w-3xl px-4 py-12">
        <p>This policy applies to payments made to <strong>{{ config('site.legal_name') }}</strong> for GetL1. Suppliers never pay, so it concerns Buyers only.</p>

        <h2>Free trial and Free plan</h2>
        <p>The 14-day trial and the Free plan cost nothing and need no card. At the end of the trial your company moves to Free automatically; nothing is charged.</p>

        <h2>Cancelling a paid plan</h2>
        <ul>
            <li>A company admin can cancel anytime from <strong>Billing → Cancel renewal</strong>, or by writing to <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a>.</li>
            <li>The plan stays active until the end of the month or year already paid for, and is not renewed after that. Your data stays available on the Free plan.</li>
            <li>We don't refund the unused part of a month or year that has started, except as below.</li>
        </ul>

        <h2>When we refund</h2>
        <ul>
            <li><strong>Duplicate or wrong charges:</strong> if you were charged twice or an amount different from the one shown, we refund the extra amount in full.</li>
            <li><strong>Renewal you didn't intend:</strong> if you write to us within 7 days of an automatic renewal and haven't run a live auction in the new period, we refund that renewal.</li>
            <li><strong>Unused credits and AI packs:</strong> extra auction credits and AI reading packs are refundable within 7 days of purchase if none of them has been used.</li>
            <li><strong>Service failure:</strong> if a fault on our side stopped you using a paid feature for a significant time, we will offer a refund or credit in proportion.</li>
        </ul>
        <p>Credits and AI reads that have been used are not refundable. A failed AI read is never charged.</p>

        <h2>How refunds are paid</h2>
        <p>Approved refunds go back to the original payment method through Razorpay within 5–7 working days of approval. Bank or card processing may take a few more days. You'll receive an email when the refund is issued.</p>

        <h2>Contact</h2>
        <p>Write to <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a> with your company name and the invoice number. We reply within one working day.</p>
    </article>
@endsection
