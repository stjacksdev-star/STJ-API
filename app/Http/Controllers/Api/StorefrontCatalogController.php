<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontCatalogService;
use Illuminate\Http\Request;

class StorefrontCatalogController extends BaseController
{
    public function __construct(
        private readonly StorefrontCatalogService $storefrontCatalogService,
    ) {
    }

    public function index(Request $request, string $country)
    {
        return $this->success(
            $this->storefrontCatalogService->forCountry($country, $request->string('q')->toString(), [
                'group' => $request->string('group')->toString(),
                'category' => $request->string('category')->toString(),
                'sort' => $request->string('sort')->toString(),
                'promo' => $request->boolean('promo'),
            ]),
            'Catalogo del storefront obtenido'
        );
    }
}
