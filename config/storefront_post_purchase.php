<?php

return [
    'integrations_enabled' => env('STOREFRONT_POST_PURCHASE_INTEGRATIONS_ENABLED', false),
    'timeout' => (int) env('STOREFRONT_POST_PURCHASE_TIMEOUT', 5),
    'connect_timeout' => (int) env('STOREFRONT_POST_PURCHASE_CONNECT_TIMEOUT', 2),

    'pos_reservation' => [
        'url' => env('STOREFRONT_POS_RESERVATION_URL', 'http://service.pos.stjacks.com:443/api/v1/productotienda-service/producto-tienda-reserva/reserva/{store}/{sku}-{size}/{reference}/{quantity}/{country}'),
        'legacy_gt_url' => env('STOREFRONT_GT_ORDER_INTEGRATION_URL'),
        'countries' => [
            'GT' => env('STOREFRONT_GT_ORDER_INTEGRATION_ENABLED', true),
            'CR' => env('STOREFRONT_CR_ORDER_INTEGRATION_ENABLED', true),
            'PA' => env('STOREFRONT_PA_ORDER_INTEGRATION_ENABLED', true),
        ],
    ],

    'honduras' => [
        'enabled' => env('STOREFRONT_HN_PRISM_ENABLED', true),
        'url' => env('STOREFRONT_HN_PRISM_URL', 'https://stjacks.com/Honduras/Prism/prism_crear_documento_items_y_tender'),
    ],
];
