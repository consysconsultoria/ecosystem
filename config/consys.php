<?php

return [

    'auth' => [
        'mode' => env('CONSYS_AUTH_MODE', 'disabled'),
        'url' => env('CONSYS_AUTH_URL'),
        'client_id' => env('CONSYS_AUTH_CLIENT_ID'),
        'client_secret' => env('CONSYS_AUTH_CLIENT_SECRET'),
        'guard' => null,
        'handler' => null,
    ],

    'directory' => [
        'application' => env('CONSYS_DIRECTORY_APPLICATION'),
        'admin_url' => env('CONSYS_DIRECTORY_ADMIN_URL'),
        'secret' => env('CONSYS_DIRECTORY_SECRET'),
        'tolerance_seconds' => 300,
        'mirror' => null,
    ],

];
