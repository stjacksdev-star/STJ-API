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
    | TODO + TODAS is historical data and remains available for both checkout
    | modalities until the commercial migration defines an explicit scope.
    */
    'legacy_ambiguous_scope' => [
        'checkout_type' => 'TODO',
        'store_scope' => 'TODAS',
    ],
];
