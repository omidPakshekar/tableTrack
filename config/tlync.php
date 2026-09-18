<?php

return [

    /*
    |--------------------------------------------------------------------------
    | T-Lync / TDSP REST API base URLs (pay.net.ly merchant docs)
    |--------------------------------------------------------------------------
    | TEST: https://uat-api.tlync.ly/api/v3/
    | LIVE: https://api.tlync.ly/api/v3/
    */
    'test_api_url' => env('TLYNC_TEST_API_URL', 'https://uat-api.tlync.ly/api/v3/'),
    'live_api_url' => env('TLYNC_LIVE_API_URL', 'https://api.tlync.ly/api/v3/'),

    'default_email' => env('TLYNC_DEFAULT_EMAIL', 'menu.tlync@hotmail.com'),

];
