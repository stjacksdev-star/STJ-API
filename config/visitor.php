<?php

return [
    'cookie' => env('STOREFRONT_VISITOR_COOKIE', 'stj_visitor'),
    'ttl_days' => (int) env('STOREFRONT_VISITOR_TTL_DAYS', 365),
    'secure' => filter_var(
        env('STOREFRONT_VISITOR_SECURE', env('APP_ENV') === 'production'),
        FILTER_VALIDATE_BOOL,
    ),
    'domain' => env('STOREFRONT_VISITOR_DOMAIN'),
    'origin' => 'WEB',
];
