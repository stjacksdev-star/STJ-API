<?php

$origins = array_values(array_unique(array_filter(array_map(
    static fn (string $origin) => trim($origin),
    array_merge(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost')),
        explode(',', (string) env('CORS_MOBILE_ALLOWED_ORIGINS', 'capacitor://localhost,http://localhost,https://localhost')),
    ),
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
