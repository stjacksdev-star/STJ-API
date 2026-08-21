<?php

return [
    'city_rate_countries' => array_values(array_filter(array_map(
        static fn (string $country): string => strtoupper(trim($country)),
        explode(',', (string) env('STOREFRONT_SHIPPING_CITY_RATE_COUNTRIES', 'HN')),
    ))),
];
