<?php

namespace App\Http\Controllers\Api;

use App\Services\ProductDetailAvailabilityService;
use Illuminate\Http\Request;

class StorefrontProductAvailabilityController extends BaseController
{
    public function __construct(
        private readonly ProductDetailAvailabilityService $availabilityService,
    ) {}

    public function show(Request $request, string $country, string $slug)
    {
        $arguments = [
            $country,
            $slug,
            $request->query('store'),
            $request->query('scope') === 'product_list' ? 'product_list' : 'product_detail',
        ];
        if ($request->filled('checkout_type')) {
            $arguments[] = $request->query('checkout_type');
        }
        $availability = $this->availabilityService->forCountryAndSlug(...$arguments);

        if (! $availability) {
            return $this->error('Disponibilidad no encontrada para este producto', 404);
        }

        return $this->success($availability, 'Disponibilidad del producto obtenida');
    }
}
