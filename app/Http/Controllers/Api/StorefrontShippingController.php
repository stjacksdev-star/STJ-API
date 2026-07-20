<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StorefrontShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StorefrontShippingController extends Controller
{
    public function locations(string $country): JsonResponse
    {
        $record = $this->country($country);
        $states = DB::table('stj_world_states')->where('country_id', $record->pai_id_world)->where('estado', 1)->orderBy('name')->get(['id', 'name']);
        return response()->json(['ok' => true, 'data' => $states]);
    }

    public function cities(string $country, int $state): JsonResponse
    {
        $record = $this->country($country);
        $valid = DB::table('stj_world_states')->where('id', $state)->where('country_id', $record->pai_id_world)->exists();
        abort_unless($valid, 404);
        $cities = DB::table('stj_world_cities')->where('state_id', $state)->where('country_id', $record->pai_id_world)->orderBy('name')->get(['id', 'name']);
        return response()->json(['ok' => true, 'data' => $cities]);
    }

    public function quote(Request $request, string $country, StorefrontShippingService $shipping): JsonResponse
    {
        $data = $request->validate(['fulfillment_type' => ['required', 'in:DOMICILIO,TIENDA'], 'city_id' => ['nullable', 'integer'], 'subtotal' => ['required', 'decimal:0,2', 'min:0']]);
        return response()->json(['ok' => true, 'data' => $shipping->quote($this->country($country), $data['fulfillment_type'], $data['city_id'] ?? null, (string) $data['subtotal'])]);
    }

    private function country(string $code): object
    {
        $country = DB::table('stj_paises')->where('pai_codigo', strtoupper($code))->first(['pai_id', 'pai_id_world', 'pai_codigo']);
        abort_unless($country && $country->pai_id_world, 404);
        return $country;
    }
}
