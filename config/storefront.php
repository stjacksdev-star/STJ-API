<?php

return [
    // Productos reservados para flujos especiales y no publicables en el
    // catalogo general. La categoria 17 corresponde a cajas de regalo.
    'excluded_product_categories' => [17],
    'gift_box_category_id' => (int) env('STOREFRONT_GIFT_BOX_CATEGORY_ID', 17),
];
