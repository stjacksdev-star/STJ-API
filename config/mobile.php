<?php

return [
    'legacy_category_asset_url' => rtrim((string) env(
        'MOBILE_LEGACY_CATEGORY_ASSET_URL',
        'https://stjacks.com/api-resources/categorias'
    ), '/'),
    'legacy_product_image_url' => rtrim((string) env(
        'MOBILE_LEGACY_PRODUCT_IMAGE_URL',
        'https://stj-assets.sfo3.cdn.digitaloceanspaces.com/images/p400'
    ), '/'),
];
