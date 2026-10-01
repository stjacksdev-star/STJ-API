<?php

return [
    'enabled' => (bool) env('HN_EXCHANGE_RATE_ENABLED', true),
    'url' => env('HN_EXCHANGE_RATE_URL', 'https://open.er-api.com/v6/latest/HNL'),
    'connect_timeout' => (int) env('HN_EXCHANGE_RATE_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('HN_EXCHANGE_RATE_TIMEOUT', 15),
    'timezone' => env('HN_EXCHANGE_RATE_TIMEZONE', 'America/Tegucigalpa'),
    'time' => env('HN_EXCHANGE_RATE_TIME', '07:00'),
];
