<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileLocationController extends Controller
{
    public function departments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
        ]);

        $country = DB::table('stj_paises')
            ->where('pai_id', (int) $data['countryId'])
            ->first(['pai_id_world']);

        if (! $country?->pai_id_world) {
            return response()->json([
                'message' => 'Pais no soportado.',
                'errors' => ['countryId' => ['Pais no soportado.']],
            ], 422);
        }

        $departments = DB::table('stj_world_states')
            ->where('country_id', (int) $country->pai_id_world)
            ->where('estado', 1)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $departments,
            'message' => 'Success',
        ]);
    }

    public function municipalities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'stateId' => ['required', 'integer', 'min:1'],
        ]);

        $country = DB::table('stj_paises')
            ->where('pai_id', (int) $data['countryId'])
            ->first(['pai_id_world']);

        if (! $country?->pai_id_world) {
            return response()->json([
                'message' => 'Pais no soportado.',
                'errors' => ['countryId' => ['Pais no soportado.']],
            ], 422);
        }

        $stateExists = DB::table('stj_world_states')
            ->where('id', (int) $data['stateId'])
            ->where('country_id', (int) $country->pai_id_world)
            ->where('estado', 1)
            ->exists();

        if (! $stateExists) {
            return response()->json([
                'message' => 'El departamento no pertenece al pais seleccionado.',
                'errors' => ['stateId' => ['El departamento no pertenece al pais seleccionado.']],
            ], 422);
        }

        $municipalities = DB::table('stj_world_cities')
            ->where('state_id', (int) $data['stateId'])
            ->where('country_id', (int) $country->pai_id_world)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $municipalities,
            'message' => 'Success',
        ]);
    }
}
