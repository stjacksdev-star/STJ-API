<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileStoreService
{
    public function forCountry(int $countryId): array
    {
        $country = DB::table('stj_paises')
            ->where('pai_id', $countryId)
            ->first(['pai_id', 'pai_codigo']);

        if (! $country) {
            throw ValidationException::withMessages([
                'countryId' => 'Pais no soportado.',
            ]);
        }

        $deliveryCode = trim((string) config(
            'inventory.domicilio_store_by_country.'.strtolower((string) $country->pai_codigo),
            ''
        ));

        return DB::table('stj_tiendas')
            ->where('tie_pais', $countryId)
            ->where('tie_productos', 1)
            ->orderByRaw('CASE WHEN tie_codigo = ? THEN 0 ELSE 1 END', [$deliveryCode])
            ->orderBy('tie_nombre')
            ->get([
                'tie_codigo',
                'tie_nombre',
                'tie_telefono',
                'tie_horario',
                'tie_correo',
                'tie_direccion',
            ])
            ->map(static function (object $store) use ($deliveryCode): array {
                $code = trim((string) $store->tie_codigo);

                return [
                    'id' => $code,
                    'nombre' => trim((string) $store->tie_nombre),
                    'telefono' => trim((string) $store->tie_telefono),
                    'horario' => trim((string) $store->tie_horario),
                    'correo' => trim((string) $store->tie_correo),
                    'direccion' => trim((string) $store->tie_direccion),
                    'tipo' => $deliveryCode !== '' && $code === $deliveryCode ? 'Domicilio' : 'Tienda',
                ];
            })
            ->values()
            ->all();
    }
}
