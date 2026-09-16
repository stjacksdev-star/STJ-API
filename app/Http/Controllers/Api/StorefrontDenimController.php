<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontDenimService;

class StorefrontDenimController extends BaseController
{
    public function __construct(private readonly StorefrontDenimService $denimService) {}

    public function show(string $country)
    {
        return $this->success($this->denimService->landing(), 'Landing Denim obtenida');
    }
}
