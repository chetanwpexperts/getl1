@extends('layouts.site')

@section('title', 'Delivery policy')
@section('description', 'GetL1 is an online service delivered instantly. No physical goods are shipped.')

@section('content')
    @include('site._policy', ['heading' => 'Delivery policy'])
    <article class="policy mx-auto max-w-3xl px-4 py-12">
        <p>GetL1 is an online software service. <strong>{{ config('site.legal_name') }}</strong> does not sell or ship any physical goods.</p>
        <h2>How the service is delivered</h2>
        <ul>
            <li>Plans, extra auction credits and AI reading packs are delivered electronically and become available in your account immediately after a successful payment, usually within a minute.</li>
            <li>An invoice is emailed to your company's billing contacts and is also available under <strong>Billing</strong>.</li>
            <li>If a paid item does not appear in your account within 1 hour of payment, write to <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a> with the Razorpay payment reference and we will fix it the same working day.</li>
        </ul>
        <h2>Goods bought through GetL1</h2>
        <p>Purchase orders created on GetL1 are agreements between the Buyer and the Supplier. Delivery of those goods, and their delivery terms, are arranged directly between them and are not handled by GetL1.</p>
    </article>
@endsection
