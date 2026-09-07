<?php

return [
    'hn' => [
        'host' => env('PRISM_HN_HOST'),
        'username' => env('PRISM_HN_USERNAME'),
        'password' => env('PRISM_HN_PASSWORD'),
        'workstation' => env('PRISM_HN_WORKSTATION'),
        'connect_timeout' => (int) env('PRISM_HN_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('PRISM_HN_TIMEOUT', 30),
        'sku_url' => env('PRISM_HN_SKU_URL'),
        'sku_token' => env('PRISM_HN_SKU_TOKEN'),
        // IDs from the reviewed HN legacy contract, overridable per installation.
        'tenant_sid' => env('PRISM_HN_TENANT_SID', '760543193000170256'),
        'subsidiary_sid' => env('PRISM_HN_SUBSIDIARY_SID', '760543193000183257'),
        'subsidiary_number' => (int) env('PRISM_HN_SUBSIDIARY_NUMBER', 1),
        'email_type_sid' => env('PRISM_HN_EMAIL_TYPE_SID', '760543304000115221'),
        'address_type_sid' => env('PRISM_HN_ADDRESS_TYPE_SID', '760543293000199567'),
        'controller_sid' => env('PRISM_HN_CONTROLLER_SID', '760543192000098255'),
        'cashier' => env('PRISM_HN_CASHIER', 'SYSADMIN'),
        'max_attempts' => (int) env('PRISM_HN_MAX_ATTEMPTS', 5),
    ],
];
