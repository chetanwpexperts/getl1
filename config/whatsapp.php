<?php

/*
 * WhatsApp alerts through Meta's WhatsApp Business Cloud API.
 *
 * Off until both WHATSAPP_PHONE_NUMBER_ID and WHATSAPP_TOKEN are set in .env. Business-started
 * messages must use templates approved by Meta: their names and wording are in
 * deploy/whatsapp-templates.md. Each template has one "Visit website" button whose URL is
 * https://<your domain>/{{1}}; GetL1 fills in the page.
 */
return [
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'token' => env('WHATSAPP_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),          // checks the signature of status webhooks
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),      // the text you type when connecting the webhook in Meta
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    'daily_limit_per_company' => (int) env('WHATSAPP_DAILY_LIMIT', 300),

    // key => [Meta template name, number of body variables]
    'templates' => [
        'rfq_invite' => ['getl1_rfq_invite', 3],          // buyer, RFQ title, quotes close
        'auction_soon' => ['getl1_auction_starting', 2],  // RFQ title, start time
        'po_issued' => ['getl1_po_issued', 3],            // buyer, PO number, amount
        'approval_needed' => ['getl1_approval_needed', 3], // RFQ title, amount, awarded by
        'payment_recorded' => ['getl1_payment_recorded', 3], // buyer, invoice number, amount
    ],
];
