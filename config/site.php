<?php

return [
    /*
     * "app": the full product (login, signup, buyer and supplier areas) plus the website.
     * "website": only the public website and the early-access form. Used on getl1.com
     * until the product goes live there; switching to "app" needs no code change.
     */
    'mode' => env('SITE_MODE', 'app'),

    // Shown on the website, policies and contact page. Set to the registered business details.
    'legal_name' => env('SITE_LEGAL_NAME', env('BILLING_SELLER_NAME', 'GetL1')),
    'address' => env('SITE_ADDRESS', env('BILLING_SELLER_ADDRESS', 'Panchkula, Haryana, India')),
    'email' => env('SITE_EMAIL', 'support@getl1.com'),
    'phone' => env('SITE_PHONE'),
    'grievance_officer' => env('SITE_GRIEVANCE_OFFICER', 'Chetan Sharma'),
    'jurisdiction' => env('SITE_JURISDICTION', 'Panchkula, Haryana'),

    // New early-access / demo requests are emailed here.
    'leads_to' => env('SITE_LEADS_TO', env('SITE_EMAIL', 'support@getl1.com')),

    'policies_updated' => '2026-10-01',

    // Branding and website content. Defaults here; staff change them in Admin → Website.
    'name' => 'GetL1',
    'tagline' => 'Reverse auctions and purchase orders for Indian manufacturers and SMEs. Make your suppliers compete, and buy at L1.',
    'logo' => null,
    'favicon' => null,
    'og_image' => null,
    'hero_headline' => null,
    'hero_subtext' => null,
    'home_title' => null,
    'home_description' => null,
    'announcement_on' => false,
    'announcement_text' => null,
    'announcement_link' => null,
    'gstin' => null,
    'whatsapp' => null,
    'social_linkedin' => null,
    'social_instagram' => null,
    'social_facebook' => null,
    'social_x' => null,
    'social_youtube' => null,
    'footer_note' => 'Made in India.',
    'ga4_id' => null,
    'google_verification' => null,
];
