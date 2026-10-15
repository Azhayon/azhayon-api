<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'token_ttl_days' => (int) env('AUTH_TOKEN_TTL_DAYS', 30),
];