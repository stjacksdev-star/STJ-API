<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontCollectionService;

class StorefrontCollectionController extends BaseController
{
    public function __construct(
        private readonly StorefrontCollectionService $storefrontCollectionService,
    ) {}

    public function show(string $country, int $collection)
    {
        $data = $this->storefrontCollectionService->find($country, $collection);

        if (! $data) {
            return $this->error('La coleccion no existe para el pais seleccionado.', 404);
        }

        return $this->success($data, 'Coleccion del storefront obtenida');
    }
}
