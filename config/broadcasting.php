<?php

/*
 * GetL1 broadcasting.
 *
 * The app talks to the Reverb server directly on localhost (never through nginx), so the
 * Reverb HTTP API is not exposed publicly. Browsers connect to wss://<app-host>/app/…,
 * which nginx proxies to the same Reverb server.
 */
return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_INTERNAL_HOST', '127.0.0.1'),
                'port' => env('REVERB_SERVER_PORT', 8090),
                'scheme' => 'http',
                'useTLS' => false,
            ],
            'client_options' => [
                'timeout' => 3,          // a slow socket server must never hold up a bid
                'connect_timeout' => 1,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
