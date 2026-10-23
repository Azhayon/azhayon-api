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
        'category' => '/category',
        'tag' => '/tag',
        'series' => '/series',
    ],

    'link_checker' => [
        'delay_ms' => 250,
        'user_agent' => 'AzhayonMediaLinkChecker/1.0 (+'.env('FRONTEND_URL', 'https://azhayonmedia.com').')',
    ],

    'api' => [
        // Shared secret between Next.js (server side only) and this API.
        'frontend_key' => env('FRONTEND_API_KEY'),
        'per_page' => 12,
        'max_per_page' => 24,
        'min_ratings' => 3,
        'cache' => ['max_age' => 60, 's_maxage' => 300, 'stale' => 600],
        'taxonomy_per_page' => 50,
        'taxonomy_max_per_page' => 100,
        'min_tag_posts' => 3,
        'home' => ['trending' => 6, 'latest' => 8, 'popular' => 6],
        'sitemap_per_page' => 2000,
        'redirects_limit' => 5000,
    ],

    // Timezone shown in the admin when picking publish times (stored as UTC).
    'admin_timezone' => env('ADMIN_TIMEZONE', 'Asia/Dhaka'),
    
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'token_ttl_days' => (int) env('AUTH_TOKEN_TTL_DAYS', 30),
];