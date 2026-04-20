<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontProductService;

class StorefrontProductController extends BaseController
{
    public function __construct(
        private readonly StorefrontProductService $storefrontProductService,
    ) {
    }

    public function show(string $country, string $slug)
    {
        $product = $this->storefrontProductService->forCountryAndSlug($country, $slug);

        if (! $product) {
            return $this->error('Producto no encontrado', 404);
        }

        return $this->success($product, 'Detalle del producto obtenido');
    }
}
