<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Promotion lifecycle authority
    |--------------------------------------------------------------------------
    |
    | "legacy" keeps the historical CodeIgniter process as the only writer.
    | "stj-api" allows promotions:update to persist lifecycle transitions.
    | "disabled" prevents both scheduled and manual lifecycle writes here.
    |
    */
    'lifecycle_authority' => env('PROMOTIONS_LIFECYCLE_AUTHORITY', 'legacy'),

    'timezone' => env('PROMOTIONS_TIMEZONE', 'America/El_Salvador'),

    /*
    | Lista separada por comas o punto y coma. Si queda vacía, el cron no
    | envía notificaciones operativas del ciclo de vida de promociones.
    */
    'notification_recipients' => array_values(array_filter(array_map(
        'trim',
        preg_split('/[,;]+/', (string) env('PROMOTIONS_NOTIFICATION_EMAILS', '')) ?: [],
    ))),

    /*
    | TODO + TODAS is historical data and remains available for both checkout
    | modalities until the commercial migration defines an explicit scope.
    */
    'legacy_ambiguous_scope' => [
        'checkout_type' => 'TODO',
        'store_scope' => 'TODAS',
    ],

    'conflict_deduplication_seconds' => 1800,
];
