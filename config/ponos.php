<?php

return [
    'password_reset' => [
        'ttl_minutes' => (int) env('PASSWORD_RESET_TTL', 60),
        'frontend_url' => env('PASSWORD_RESET_URL', env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password'),
    ],

    'welcome' => [
        'login_url' => env('WELCOME_LOGIN_URL', env('FRONTEND_URL', 'http://localhost:3000') . '/login'),
        'generated_password_length' => (int) env('GENERATED_PASSWORD_LENGTH', 16),
    ],
];
