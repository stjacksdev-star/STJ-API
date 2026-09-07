<?php

return [
    'hn' => [
        'host' => env('PRISM_HN_HOST'),
        'username' => env('PRISM_HN_USERNAME'),
        'password' => env('PRISM_HN_PASSWORD'),
        'workstation' => env('PRISM_HN_WORKSTATION'),
        'connect_timeout' => (int) env('PRISM_HN_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('PRISM_HN_TIMEOUT', 30),
    ],
];
