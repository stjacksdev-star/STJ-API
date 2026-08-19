<?php

return [
    'environment' => env('POWERTRANZ_ENVIRONMENT', 'staging'),
    'sale_url' => env('POWERTRANZ_URL', 'https://staging.ptranz.com/api/spi/sale'),
    'payment_url' => env('POWERTRANZ_PAYMENT_URL', 'https://staging.ptranz.com/api/spi/payment'),
    'connect_timeout' => (int) env('POWERTRANZ_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('POWERTRANZ_TIMEOUT', 20),
    'return_base_url' => env('POWERTRANZ_RETURN_BASE_URL', ''),
    'frontend_result_url' => env('POWERTRANZ_FRONTEND_RESULT_URL', ''),
    'return_token_ttl_minutes' => (int) env('POWERTRANZ_RETURN_TOKEN_TTL_MINUTES', 60),
    'credentials' => collect(['sv', 'gt', 'cr', 'pa', 'hn'])->mapWithKeys(fn ($country) => [$country => [
        'id' => env('POWERTRANZ_'.strtoupper($country).'_ID', ''),
        'password' => env('POWERTRANZ_'.strtoupper($country).'_PASSWORD', ''),
    ]])->all(),
    // ISO 4217 numérico utilizado exclusivamente en los mensajes a PowerTranz.
    'currencies' => [
        'sv' => env('POWERTRANZ_SV_CURRENCY_CODE', '840'),
        'gt' => env('POWERTRANZ_GT_CURRENCY_CODE', '320'),
        'cr' => env('POWERTRANZ_CR_CURRENCY_CODE', '188'),
        'pa' => env('POWERTRANZ_PA_CURRENCY_CODE', '840'),
        'hn' => env('POWERTRANZ_HN_CURRENCY_CODE', '340'),
    ],
];
