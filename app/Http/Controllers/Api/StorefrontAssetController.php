<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontAssetService;

class StorefrontAssetController extends BaseController
{
    public function __construct(
        private readonly StorefrontAssetService $storefrontAssetService,
    ) {
    }

    public function show(string $country)
    {
        return $this->success(
            $this->storefrontAssetService->forCountry($country),
            'Assets del storefront obtenidos'
        );
    }
}
