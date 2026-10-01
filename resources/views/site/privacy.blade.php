@extends('layouts.site')

@section('title', 'Privacy policy')
@section('description', 'How GetL1 collects, uses and protects personal data.')

@php($n = config('site.legal_name'))

@section('content')
    @include('site._policy', ['heading' => 'Privacy policy'])
    <article class="policy mx-auto max-w-3xl px-4 py-12">
        <p>This policy explains how <strong>{{ $n }}</strong> ("GetL1", "we") handles personal data when you visit getl1.com or use the GetL1 service. We follow the Information Technology Act, 2000 and its rules, and the Digital Personal Data Protection Act, 2023.</p>

        <h2>What we collect</h2>
        <ul>
            <li><strong>Account details:</strong> name, work email, mobile number, company name, address, GSTIN, PAN and Udyam number.</li>
            <li><strong>Business content:</strong> RFQs, quotations, bids, awards, purchase orders and documents you upload, such as GST or Udyam certificates.</li>
            <li><strong>Payment records:</strong> plan, amounts, invoice details and Razorpay payment references. Card, UPI and bank details are handled by Razorpay, not stored by us.</li>
            <li><strong>Technical data:</strong> IP address, browser type, login times and security events, used to keep accounts safe.</li>
            <li><strong>Website enquiries:</strong> details you enter in the contact or early-access form.</li>
        </ul>

        <h2>Why we use it</h2>
        <ul>
            <li>To provide the service: run RFQs and auctions, send invitations, reminders, results and purchase orders.</li>
            <li>To bill paid plans and issue invoices.</li>
            <li>To verify suppliers and prevent fraud and misuse.</li>
            <li>To answer enquiries and support requests, and to tell you about important changes.</li>
            <li>To meet legal obligations, such as tax records.</li>
        </ul>
        <p>We do not sell personal data, and we do not show advertising inside the service.</p>

        <h2>Who we share it with</h2>
        <ul>
            <li><strong>Other users, as the service requires:</strong> a Buyer's RFQ goes to the Suppliers it invites; a Supplier's quotation and bids go to that Buyer. Suppliers never see other Suppliers' names or prices.</li>
            <li><strong>Service providers</strong> who process data for us under contract: hosting, email delivery, Razorpay for payments, and Anthropic for the optional AI reading feature (only the text or file you choose to have read).</li>
            <li><strong>Authorities</strong> when required by law.</li>
        </ul>

        <h2>How long we keep it</h2>
        <p>Account and business records are kept while your company uses GetL1 and afterwards as long as tax and company laws require (generally 6 to 8 years for invoices and accounts). Files uploaded only for AI reading are deleted after 30 days. Security logs are kept for 90 days.</p>

        <h2>How we protect it</h2>
        <p>Encrypted connections (HTTPS), each company's data kept separate, private file storage, strict access roles, an append-only audit trail, login lockout after repeated failures, and two-step login for our staff. No system is perfectly secure, but we work to protect your data and will notify you and the authorities of a breach as the law requires.</p>

        <h2>Your rights</h2>
        <p>You can ask to access, correct or delete your personal data, withdraw consent for optional uses, or nominate someone to act for you, by writing to <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a>. Some records must be kept by law even after an account is closed.</p>

        <h2>Cookies</h2>
        @if (config('site.ga4_id'))
            <p>We use the cookies needed to keep you logged in and to protect forms, and Google Analytics cookies on the public website to understand how visitors use it (IP addresses are anonymised). We do not use advertising cookies. You can block analytics cookies in your browser without affecting the service.</p>
        @else
            <p>We use only the cookies needed to keep you logged in and to protect forms. We do not use advertising cookies.</p>
        @endif

        <h2>Grievance Officer</h2>
        <p>{{ config('site.grievance_officer') }}, {{ $n }}, {{ config('site.address') }}. Email: <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a>. We acknowledge complaints within 24 hours and aim to resolve them within 15 days.</p>
    </article>
@endsection
