<?php

$recipients = static fn (string $key): array => array_values(array_unique(array_filter(array_map(
    'trim',
    preg_split('/[,;]+/', (string) env($key, '')) ?: [],
), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));

return [
    'enabled' => filter_var(env('INVENTORY_REPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'timezone' => env('INVENTORY_REPORT_TIMEZONE', 'America/El_Salvador'),
    'connect_timeout_seconds' => (int) env('INVENTORY_REPORT_CONNECT_TIMEOUT', 10),
    'timeout_seconds' => (int) env('INVENTORY_REPORT_TIMEOUT', 60),
    'max_attempts' => (int) env('INVENTORY_REPORT_MAX_ATTEMPTS', 3),
    'batch_size' => (int) env('INVENTORY_REPORT_BATCH_SIZE', 100),
    'retry_batch_size' => (int) env('INVENTORY_REPORT_RETRY_BATCH_SIZE', 20),
    'final_retry_batch_size' => (int) env('INVENTORY_REPORT_FINAL_RETRY_BATCH_SIZE', 5),
    'start_time' => env('INVENTORY_REPORT_START_TIME', '03:00'),
    'close_time' => env('INVENTORY_REPORT_CLOSE_TIME', '06:00'),
    'email_time' => env('INVENTORY_REPORT_EMAIL_TIME', '08:00'),
    'storage_path' => env('INVENTORY_REPORT_STORAGE_PATH', storage_path('app/private/inventory-reports')),

    'countries' => [
        'SV' => [
            'id' => 1,
            'name' => 'El Salvador',
            'excel_name' => 'El Salvador',
            'adapter' => 'sv',
            'url' => env('INVENTORY_REPORT_SV_URL'),
            'token' => env('INVENTORY_API_TOKEN'),
            'stores' => ['019', '002', '024', '004', '009', '003', '015', '006', '007', '001', '005', '023', '027', '018', '021', '014', '022', '034', '035', '57', '039', '040', '041', '042', '043', '045'],
        ],
        'GT' => [
            'id' => 2,
            'name' => 'Guatemala',
            'excel_name' => 'Guatemala',
            'adapter' => 'regional',
            'url' => env('INVENTORY_REPORT_REGIONAL_URL'),
            'token' => env('INVENTORY_API_TOKEN'),
            'stores' => [],
        ],
        'CR' => [
            'id' => 3,
            'name' => 'Costa Rica',
            'excel_name' => 'Costa Rica',
            'adapter' => 'regional',
            'url' => env('INVENTORY_REPORT_REGIONAL_URL'),
            'token' => env('INVENTORY_API_TOKEN'),
            'stores' => [],
        ],
        'PA' => [
            'id' => 5,
            'name' => 'Panama',
            'excel_name' => 'Panama',
            'adapter' => 'regional',
            'url' => env('INVENTORY_REPORT_REGIONAL_URL'),
            'token' => env('INVENTORY_API_TOKEN'),
            'stores' => [],
        ],
        'HN' => [
            'id' => 7,
            'name' => 'Honduras',
            'excel_name' => 'Honduras',
            'adapter' => 'hn',
            'url' => env('INVENTORY_REPORT_HN_URL'),
            'token' => env('PRISM_HN_SKU_TOKEN'),
            'stores' => ['005', '002', '001', '003', '006'],
        ],
    ],

    'mail' => [
        'from_address' => env('INVENTORY_REPORT_MAIL_FROM_ADDRESS'),
        'from_name' => env('INVENTORY_REPORT_MAIL_FROM_NAME', "St. Jack's Automatico"),
        'to' => $recipients('INVENTORY_REPORT_MAIL_TO'),
        'cc' => $recipients('INVENTORY_REPORT_MAIL_CC'),
        'bcc' => $recipients('INVENTORY_REPORT_MAIL_BCC'),
    ],
];
