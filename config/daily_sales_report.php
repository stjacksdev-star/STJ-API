<?php

$recipients = static fn (string $key): array => array_values(array_unique(array_filter(array_map(
    'trim',
    preg_split('/[,;]+/', (string) env($key, '')) ?: [],
), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));

return [
    'enabled' => filter_var(env('DAILY_SALES_REPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'to' => $recipients('DAILY_SALES_REPORT_TO'),
    'cc' => $recipients('DAILY_SALES_REPORT_CC'),
    'bcc' => $recipients('DAILY_SALES_REPORT_BCC'),
    'timezone' => env('DAILY_SALES_REPORT_TIMEZONE', 'America/El_Salvador'),
    'time' => env('DAILY_SALES_REPORT_TIME', '00:10'),
    'gtq_usd_rate' => (float) env('DAILY_SALES_REPORT_GTQ_USD_RATE', 0.13049),
    'crc_usd_rate' => (float) env('DAILY_SALES_REPORT_CRC_USD_RATE', 0.0017594),
    'hnl_usd_fallback_rate' => (float) env('DAILY_SALES_REPORT_HNL_USD_FALLBACK_RATE', 0),
];
