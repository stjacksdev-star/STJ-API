<?php

return [
    'automation_enabled' => env('PUSH_WEB_AUTOMATION_ENABLED', false),
    'evaluate_limit' => max(1, (int) env('PUSH_WEB_EVALUATE_LIMIT', 500)),
    'send_limit' => max(1, (int) env('PUSH_WEB_SEND_LIMIT', 100)),
    'max_attempts' => max(1, (int) env('PUSH_WEB_MAX_ATTEMPTS', 3)),
    'base_retry_minutes' => max(1, (int) env('PUSH_WEB_BASE_RETRY_MINUTES', 5)),
    'processing_timeout_minutes' => max(1, (int) env('PUSH_WEB_PROCESSING_TIMEOUT_MINUTES', 15)),
    'public_base_url' => rtrim((string) env('PUSH_WEB_PUBLIC_BASE_URL', env('APP_URL')), '/'),
    'click_url_ttl_days' => max(1, (int) env('PUSH_WEB_CLICK_URL_TTL_DAYS', 30)),
];
