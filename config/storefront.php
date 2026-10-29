<?php

return [
    'catalog_only_countries' => array_values(array_filter(array_map(
        static fn (string $country): string => strtolower(trim($country)),
        explode(',', (string) env('STOREFRONT_CATALOG_ONLY_COUNTRIES', 've')),
    ))),
];
