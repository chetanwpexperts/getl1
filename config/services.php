<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Razorpay: test keys (rzp_test_…) on staging, live keys only in production .env.
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    // Claude (Anthropic API) for reading requirements into RFQs. Key only in .env.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 90),
        // For the cost estimate kept per job (USD per million tokens, and the rupee rate).
        'usd_per_mtok_in' => (float) env('ANTHROPIC_USD_PER_MTOK_IN', 1),
        'usd_per_mtok_out' => (float) env('ANTHROPIC_USD_PER_MTOK_OUT', 5),
        'inr_per_usd' => (float) env('ANTHROPIC_INR_PER_USD', 88),
    ],

];
