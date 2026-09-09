<?php

return [
    'password_reset' => [
        // Hours a reset token stays valid before it expires.
        'token_ttl_hours' => (int) env('SUPERADMIN_RESET_TOKEN_TTL_HOURS', 6),

        // Front-end page that captures the new password; the token is appended as a query string.
        // That page then POSTs to /api/super-man/reset-password to perform the reset.
        'url' => env('SUPERADMIN_RESET_PASSWORD_URL', env('APP_URL', 'http://localhost') . '/super-man/reset-password'),
    ],
];
