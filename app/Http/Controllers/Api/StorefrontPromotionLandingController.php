<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontPromotionLandingService;
use Illuminate\Http\Request;

class StorefrontPromotionLandingController extends BaseController
{
    public function __construct(
        private readonly StorefrontPromotionLandingService $service,
    ) {}

    public function show(Request $request, string $country, int $promotion)
    {
        $data = $this->service->find($country, $promotion, [
            'brand' => $request->string('brand')->toString(),
            'gender' => $request->string('gender')->toString(),
            'sort' => $request->string('sort')->toString(),
            'page' => max(1, $request->integer('page', 1)),
            'perPage' => min(48, max(4, $request->integer('per_page', 24))),
            'checkoutType' => $request->string('checkout_type', 'DOMICILIO')->toString(),
            'storeCode' => $request->string('store')->toString(),
        ]);

        if (! $data) {
            return $this->error('La promocion no existe o no esta activa para el pais seleccionado.', 404);
        }

        return $this->success($data, 'Landing de promocion obtenida');
    }
}
