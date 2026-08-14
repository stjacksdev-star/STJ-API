<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontCouponLandingService;

class StorefrontCouponLandingController extends BaseController
{
    public function __construct(private readonly StorefrontCouponLandingService $landing) {}
    public function show(string $country, int $header)
    {
        $data = $this->landing->find($country, $header);
        return $data ? $this->success($data, 'Productos aplicables al cupón.') : $this->error('El cupón no está disponible o no tiene un alcance de productos público.', 404);
    }
}
