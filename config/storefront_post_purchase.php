<?php

return [
    'integrations_enabled' => env('STOREFRONT_POST_PURCHASE_INTEGRATIONS_ENABLED', false),
    'timeout' => (int) env('STOREFRONT_POST_PURCHASE_TIMEOUT', 5),
    'connect_timeout' => (int) env('STOREFRONT_POST_PURCHASE_CONNECT_TIMEOUT', 2),

    'guatemala' => [
        'enabled' => env('STOREFRONT_GT_ORDER_INTEGRATION_ENABLED', true),
        'url' => env('STOREFRONT_GT_ORDER_INTEGRATION_URL', 'https://service.pos.stjacks.com/api/v1/productotienda-service/producto-tienda-reserva/reserva/{store}/{sku}-{size}/{reference}/{quantity}/GT'),
    ],

    'honduras' => [
        'enabled' => env('STOREFRONT_HN_PRISM_ENABLED', true),
        'url' => env('STOREFRONT_HN_PRISM_URL', 'https://stjacks.com/Honduras/Prism/prism_crear_documento_items_y_tender'),
    ],
];
