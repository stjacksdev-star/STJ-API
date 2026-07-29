<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StorefrontCheckoutCatalogController extends BaseController
{
    public function index(string $country): JsonResponse
    {
        $storefrontCountry = DB::table('stj_paises')
            ->where('pai_codigo', strtoupper($country))
            ->first(['pai_id_world']);
        abort_unless($storefrontCountry?->pai_id_world, 404);

        return $this->success([
            'documentTypes' => ['DUI', 'DPI', 'Cédula', 'Carné de residente', 'Licencia de conducir', 'Pasaporte', 'Otro'],
            'defaultCountryId' => (int) $storefrontCountry->pai_id_world,
            'countries' => DB::table('stj_world_countries')
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$storefrontCountry->pai_id_world])
                ->orderBy('name')
                ->get(['id', 'iso2', 'name', 'phonecode']),
        ]);
    }

    public function states(int $country): JsonResponse
    {
        abort_unless(DB::table('stj_world_countries')->where('id', $country)->exists(), 404);

        return $this->success(DB::table('stj_world_states')
            ->where('country_id', $country)
            ->where('estado', 1)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public function cities(int $country, int $state): JsonResponse
    {
        $valid = DB::table('stj_world_states')
            ->where('id', $state)
            ->where('country_id', $country)
            ->exists();
        abort_unless($valid, 404);

        return $this->success(DB::table('stj_world_cities')
            ->where('state_id', $state)
            ->where('country_id', $country)
            ->orderBy('name')
            ->get(['id', 'name']));
    }
}
