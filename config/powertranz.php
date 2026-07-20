<?php

return [
    'environment' => env('POWERTRANZ_ENVIRONMENT', 'staging'),
    'sale_url' => env('POWERTRANZ_URL', 'https://staging.ptranz.com/api/spi/sale'),
    'payment_url' => env('POWERTRANZ_PAYMENT_URL', 'https://staging.ptranz.com/api/spi/payment'),
    'connect_timeout' => (int) env('POWERTRANZ_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('POWERTRANZ_TIMEOUT', 20),
    'frontend_result_url' => env('POWERTRANZ_FRONTEND_RESULT_URL', ''),
    'credentials' => collect(['sv', 'gt', 'cr', 'pa', 'hn'])->mapWithKeys(fn ($country) => [$country => [
        'id' => env('POWERTRANZ_'.strtoupper($country).'_ID', ''),
        'password' => env('POWERTRANZ_'.strtoupper($country).'_PASSWORD', ''),
    ]])->all(),
    'currencies' => ['sv' => '840', 'gt' => '320', 'cr' => '188', 'pa' => '840', 'hn' => '340'],
];
