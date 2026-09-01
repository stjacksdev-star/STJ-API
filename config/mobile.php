<?php

return [
    'registration_country_codes' => ['SV', 'GT', 'CR', 'HN'],
    'auth_token_days' => max(1, (int) env('MOBILE_AUTH_TOKEN_DAYS', 30)),
    'legacy_category_asset_url' => rtrim((string) env(
        'MOBILE_LEGACY_CATEGORY_ASSET_URL',
        'https://stjacks.com/api-resources/categorias'
    ), '/'),
    'legacy_product_image_url' => rtrim((string) env(
        'MOBILE_LEGACY_PRODUCT_IMAGE_URL',
        'https://stj-assets.sfo3.cdn.digitaloceanspaces.com/images/p400'
    ), '/'),
];
