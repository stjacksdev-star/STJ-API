<?php

return [
    'legacy_category_asset_url' => rtrim((string) env(
        'MOBILE_LEGACY_CATEGORY_ASSET_URL',
        'https://stjacks.com/api-resources/categorias'
    ), '/'),
];
