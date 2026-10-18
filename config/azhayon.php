<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'images' => [
        'widths' => [1600, 1200, 768, 480],
        'quality' => 80,
        'max_bytes' => 5 * 1024 * 1024,
        'max_megapixels' => 24,
        'min_width' => 320,
    ],

    'paths' => [
        'post' => '/blog',
    ],

    // Timezone shown in the admin when picking publish times (stored as UTC).
    'admin_timezone' => env('ADMIN_TIMEZONE', 'Asia/Dhaka'),
    
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'token_ttl_days' => (int) env('AUTH_TOKEN_TTL_DAYS', 30),
];