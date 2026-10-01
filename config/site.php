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

    'policies_updated' => '1 October 2026',
];
