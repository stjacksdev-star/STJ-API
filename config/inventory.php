<?php

return [
    'rule_cache_seconds' => (int) env('INVENTORY_RULE_CACHE_SECONDS', 300),

    'sources' => [
        'external_api',
        'local_inventory',
    ],

    'scopes' => [
        'product_list',
        'product_detail',
        'checkout',
    ],

    'external' => [
        'token' => env('INVENTORY_API_TOKEN', env('STJ_API_TOKEN', '')),
        'sv_detail_url' => env('INVENTORY_SV_DETAIL_URL', env('STJ_API_NPOS', '') ? 'https://'.env('STJ_API_NPOS').'/api/existencias/detalle' : ''),
        'sv_categories_url' => env('INVENTORY_SV_CATEGORIES_URL', env('STJ_API_NPOS', '') ? 'https://'.env('STJ_API_NPOS').'/api/existencias/categorias' : ''),
        'generic_detail_url' => env('INVENTORY_GENERIC_DETAIL_URL', env('STJ_API', '') ? 'http://'.env('STJ_API').'/API-Inventario/api/articulos/existenciaPorTiendaDetalle' : ''),
        'generic_categories_url' => env('INVENTORY_GENERIC_CATEGORIES_URL', env('STJ_API', '') ? 'http://'.env('STJ_API').'/API-Inventario/api/articulos/existenciaPorTiendaDetalle' : ''),
        'timeout_seconds' => (int) env('INVENTORY_API_TIMEOUT_SECONDS', 8),
    ],

    'defaults' => [
        'product_list' => [
            'source' => 'local_inventory',
            'fallback_source' => null,
        ],
        'product_detail' => [
            'source' => 'external_api',
            'fallback_source' => 'local_inventory',
        ],
        'checkout' => [
            'source' => 'external_api',
            'fallback_source' => null,
        ],
    ],

    'stores_by_country' => [
        'sv' => ['019', '002', '024', '004', '009', '003', '015', '006', '007', '001', '005', '023', '027', '018', '021', '014', '022', '034', '035', '57', '040', '041', '042', '043'],
        'gt' => ['1', '2', '4', '5', '6', '7', '8', '9', '10', '11', '12', '15', '16', '17', '18', '19', '21', '22', '31', '32', '33', '35', '36'],
        'cr' => ['1', '2'],
        'pa' => ['1'],
        'do' => ['1'],
        'hn' => ['1'],
    ],

    'default_store_by_country' => [
        'sv' => '57',
        'gt' => '2',
        'cr' => '1',
        'pa' => '1',
        'do' => '1',
        'hn' => '1',
    ],

    'domicilio_store_by_country' => [
        'sv' => '57',
        'gt' => '2',
        'cr' => '1',
    ],
];
