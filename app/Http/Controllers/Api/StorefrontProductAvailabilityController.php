<?php

namespace App\Http\Controllers\Api;

use App\Services\ProductDetailAvailabilityService;
use Illuminate\Http\Request;

class StorefrontProductAvailabilityController extends BaseController
{
    public function __construct(
        private readonly ProductDetailAvailabilityService $availabilityService,
    ) {
    }

    public function show(Request $request, string $country, string $slug)
    {
        $availability = $this->availabilityService->forCountryAndSlug(
            $country,
            $slug,
            $request->query('store')
        );

        if (! $availability) {
            return $this->error('Disponibilidad no encontrada para este producto', 404);
        }

        return $this->success($availability, 'Disponibilidad del producto obtenida');
    }
}
