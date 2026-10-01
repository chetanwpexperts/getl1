<?php

/*
 * GetL1's own billing details: who the invoices come from, GST, and one-off auction pricing.
 * GST, the GSTIN and the seller details are managed in Admin → Billing & GST (App\Services\BillingSettings);
 * the values here are only the starting defaults. Prices on the plans are always before GST.
 */
return [
    'gst_enabled' => (bool) env('BILLING_GST_ENABLED', false),
    'gst_rate' => (float) env('BILLING_GST_RATE', 18),

    'seller' => [
        // Name, address and email: Admin → Billing & GST; empty falls back to the website's legal name, address and email.
        'name' => env('BILLING_SELLER_NAME'),
        'gstin' => env('BILLING_SELLER_GSTIN'),
        'address' => env('BILLING_SELLER_ADDRESS'),
        'state_code' => env('BILLING_SELLER_STATE_CODE', '06'), // first two digits of a GSTIN (06 = Haryana)
        'email' => env('BILLING_SELLER_EMAIL'),
        'sac' => env('BILLING_SAC'),                             // service accounting code printed on tax invoices
    ],

    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'GL1'),

    // One live auction beyond the monthly plan limit.
    'auction_credit_price' => (float) env('BILLING_AUCTION_CREDIT_PRICE', 799),
    'auction_credit_max_qty' => 20,

    // AI pack: prepaid AI reads for plans without AI, or when the month's reads are used up.
    'ai_pack_price' => (float) env('BILLING_AI_PACK_PRICE', 199),
    'ai_pack_reads' => (int) env('BILLING_AI_PACK_READS', 50),
    'ai_pack_max_qty' => 10,

    // Number of billing cycles a Razorpay subscription runs before it needs renewing.
    'cycles' => ['monthly' => 120, 'yearly' => 10],
];
