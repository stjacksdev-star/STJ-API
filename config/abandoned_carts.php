<?php

$recipients = static fn (string $key): array => array_values(array_unique(array_filter(array_map(
    'trim',
    preg_split('/[,;]+/', (string) env($key, '')) ?: [],
), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));

return [
    'enabled' => filter_var(env('ABANDONED_CART_REPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'to' => $recipients('ABANDONED_CART_REPORT_TO'),
    'cc' => $recipients('ABANDONED_CART_REPORT_CC'),
    'bcc' => $recipients('ABANDONED_CART_REPORT_BCC'),
    'timezone' => env('ABANDONED_CART_REPORT_TIMEZONE', 'America/El_Salvador'),
    'lookback_hours' => max(1, (int) env('ABANDONED_CART_REPORT_LOOKBACK_HOURS', 24)),
    'inactivity_minutes' => max(1, (int) env('ABANDONED_CART_REPORT_INACTIVITY_MINUTES', 60)),
];
