<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontPromotionService;

class StorefrontPromotionController extends BaseController
{
    public function __construct(
        private readonly StorefrontPromotionService $storefrontPromotionService,
    ) {
    }

    public function index(string $country)
    {
        return $this->success(
            $this->storefrontPromotionService->activeForCountry($country),
            'Promociones activas del storefront obtenidas'
        );
    }
}
