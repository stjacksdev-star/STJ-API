<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontProductService;
use Illuminate\Http\Request;

class StorefrontProductController extends BaseController
{
    public function __construct(
        private readonly StorefrontProductService $storefrontProductService,
    ) {}

    public function show(Request $request, string $country, string $slug)
    {
        $product = $this->storefrontProductService->forCountryAndSlug($country, $slug, [
            'checkoutType' => $request->string('checkout_type', 'DOMICILIO')->toString(),
            'storeCode' => $request->string('store')->toString(),
        ]);

        if (! $product) {
            return $this->error('Producto no encontrado', 404);
        }

        return $this->success($product, 'Detalle del producto obtenido');
    }
}
