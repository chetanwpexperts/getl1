<?php

/*
 * Browser push (Web Push with VAPID). Keys are generated on the server with
 *   php artisan getl1:webpush-keys
 * which writes them into .env. Without keys, push is simply off; the bell and live alerts still work.
 */
return [
    'public_key' => env('WEBPUSH_PUBLIC_KEY'),
    'private_key' => env('WEBPUSH_PRIVATE_KEY'),
    'subject' => env('WEBPUSH_SUBJECT', 'mailto:support@getl1.com'),
];
