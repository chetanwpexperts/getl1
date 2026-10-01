@extends('layouts.site')

@section('title', 'Terms of use')
@section('description', 'The terms that apply to buyers and suppliers using GetL1.')

@php($n = config('site.legal_name'))

@section('content')
    @include('site._policy', ['heading' => 'Terms of use'])
    <article class="policy mx-auto max-w-3xl px-4 py-12">
        <p>These terms apply to everyone who uses GetL1 (the "Service"), the online procurement and reverse auction platform at getl1.com operated by <strong>{{ $n }}</strong>, {{ config('site.address') }} ("GetL1", "we", "us"). By creating an account or using the Service you agree to these terms on behalf of yourself and the company you represent.</p>

        <h2>1. What GetL1 does</h2>
        <p>GetL1 lets a buying company ("Buyer") publish requirements (RFQs), invite suppliers it chooses, collect sealed quotations, run live reverse auctions, approve awards and issue purchase orders. Companies that quote ("Suppliers") use the Service free of charge.</p>
        <p>GetL1 is a software tool. We are not a party to any contract between a Buyer and a Supplier, we do not buy or sell goods, and we do not guarantee the quality, delivery, payment or legality of any goods, services or purchase orders. Buyers and Suppliers are responsible for their own commercial decisions, contracts, taxes and compliance.</p>

        <h2>2. Accounts</h2>
        <ul>
            <li>You must be at least 18 years old and authorised to act for your company.</li>
            <li>Information you give us, including GSTIN, PAN and contact details, must be true and current.</li>
            <li>Keep your password private. You are responsible for activity on your account and must tell us promptly at <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a> if you suspect misuse.</li>
            <li>A company admin controls who in their company has access and with which role.</li>
        </ul>

        <h2>3. Fair use of auctions</h2>
        <ul>
            <li>Bids and quotations are firm offers by the Supplier on the terms stated in the RFQ, unless the Buyer's terms say otherwise.</li>
            <li>Do not place fake or shill bids, collude with other bidders, share login access to influence an auction, or try to discover other Suppliers' identities or prices.</li>
            <li>Do not use the Service to send spam, to post unlawful, misleading or infringing content, or to probe, overload or break the Service's security.</li>
            <li>We may suspend or close accounts that break these rules, and we may cancel an auction where we see technical failure or abuse.</li>
        </ul>

        <h2>4. Plans, payments and taxes</h2>
        <p>Buyers may use the Free plan or a paid plan as shown on the <a href="{{ route('site.pricing') }}">pricing page</a>. Paid plans renew automatically each month or year until cancelled. Extra auction credits and AI reading packs are one-time purchases. Payments are processed by Razorpay; we do not store your card or bank details. Prices are in Indian rupees and taxes are applied as required by law. Cancellations and refunds are covered by our <a href="{{ route('site.refunds') }}">cancellation and refund policy</a>.</p>

        <h2>5. Your data</h2>
        <p>Your company owns the content it puts into GetL1, including RFQs, quotations, bids and documents. You give us permission to store and process it only to provide the Service, and to share it with the parties you choose (for example, an RFQ with the Suppliers you invite). How we handle personal data is explained in our <a href="{{ route('site.privacy') }}">privacy policy</a>.</p>
        <p>The AI reading feature sends the text or file you choose to our AI provider to extract RFQ fields. Always review what it fills in before saving or publishing; you remain responsible for the final RFQ.</p>

        <h2>6. Availability and changes</h2>
        <p>We work to keep GetL1 available and secure, but the Service is provided "as is" and may occasionally be unavailable for maintenance or reasons beyond our control. Auctions use the server clock; if a technical problem affects an auction, the Buyer may re-run it. We may improve or change features; we will give notice of material changes to these terms by email or in the Service.</p>

        <h2>7. Limitation of liability</h2>
        <p>To the extent permitted by law, GetL1 is not liable for indirect or consequential losses, lost profits, or losses arising from transactions between Buyers and Suppliers. Our total liability for any claim relating to the Service is limited to the fees the Buyer paid to us in the three months before the claim.</p>

        <h2>8. Ending your use</h2>
        <p>You may stop using GetL1 at any time. A company admin may ask us to close the company account by writing to {{ config('site.email') }}. Records we must keep by law (for example invoices and audit records) are retained as described in the privacy policy.</p>

        <h2>9. Law and disputes</h2>
        <p>These terms are governed by the laws of India. Courts at {{ config('site.jurisdiction') }} have exclusive jurisdiction, subject to any mandatory consumer protection rights you may have.</p>

        <h2>10. Contact and grievances</h2>
        <p>Questions or complaints: <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a>. Grievance Officer: {{ config('site.grievance_officer') }}, {{ $n }}, {{ config('site.address') }}. We acknowledge complaints within 24 hours and aim to resolve them within 15 days.</p>
    </article>
@endsection
