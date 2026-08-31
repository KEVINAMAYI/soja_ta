<?php

return [
    'test' => [
        'base_url' => env('CLIENT_PORTAL_SANDBOX_BASE_URL', 'https://sandbox-api.sojata.com/v1/'),
        'daily_quota' => (int) env('CLIENT_PORTAL_SANDBOX_DAILY_QUOTA', 10000),
    ],
    'production' => [
        'base_url' => env('CLIENT_PORTAL_PRODUCTION_BASE_URL', 'https://api.sojata.com/v1/'),
        'daily_quota' => (int) env('CLIENT_PORTAL_PRODUCTION_DAILY_QUOTA', 100000),
    ],
];
