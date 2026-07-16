<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontBrandService;
use Illuminate\Http\Request;

class StorefrontBrandController extends BaseController
{
    public function __construct(
        private readonly StorefrontBrandService $storefrontBrandService,
    ) {}

    public function show(Request $request, string $country, string $brand)
    {
        $payload = $this->storefrontBrandService->show($country, $brand, [
            'q' => $request->string('q')->toString(),
            'group' => $request->string('group')->toString(),
            'category' => $request->string('category')->toString(),
            'sort' => $request->string('sort')->toString(),
            'page' => $request->integer('page', 1),
        ]);

        if (! $payload) {
            return $this->error('Marca no encontrada', 404);
        }

        return $this->success($payload, 'Marca del storefront obtenida');
    }
}
