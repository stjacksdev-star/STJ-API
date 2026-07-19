<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontStoreService;
use Illuminate\Http\Request;

class StorefrontStoreController extends BaseController
{
    public function __construct(private readonly StorefrontStoreService $stores) {}

    public function index(Request $request, string $country)
    {
        $data = $request->validate(['latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180']]);

        return $this->success($this->stores->forCountry($country, isset($data['latitude']) ? (float) $data['latitude'] : null, isset($data['longitude']) ? (float) $data['longitude'] : null), 'Tiendas del storefront obtenidas');
    }
}
