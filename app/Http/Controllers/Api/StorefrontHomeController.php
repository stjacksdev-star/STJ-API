<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontHomeService;

class StorefrontHomeController extends BaseController
{
    public function __construct(
        private readonly StorefrontHomeService $storefrontHomeService,
    ) {
    }

    public function show(string $country)
    {
        return $this->success(
            $this->storefrontHomeService->forCountry($country),
            'Contenido del home obtenido'
        );
    }
}
