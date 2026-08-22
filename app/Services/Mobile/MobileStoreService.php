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

        $deliveryStore = $deliveryCode === ''
            ? null
            : DB::table('stj_tiendas')
                ->where('tie_pais', $countryId)
                ->where('tie_codigo', $deliveryCode)
                ->first([
                    'tie_codigo',
                    'tie_nombre',
                    'tie_telefono',
                    'tie_horario',
                    'tie_correo',
                    'tie_direccion',
                ]);

        $records = collect();
        if ($deliveryCode !== '') {
            $records->push($deliveryStore
                ? $this->mapStore($deliveryStore, 'Domicilio')
                : [
                    'id' => $deliveryCode,
                    'nombre' => 'Domicilio',
                    'telefono' => '',
                    'horario' => '',
                    'correo' => '',
                    'direccion' => '',
                    'tipo' => 'Domicilio',
                ]);
        }

        $physicalStores = DB::table('stj_tiendas')
            ->where('tie_pais', $countryId)
            ->where('tie_productos', 1)
            ->when($deliveryCode !== '', fn ($query) => $query->where('tie_codigo', '<>', $deliveryCode))
            ->orderBy('tie_nombre')
            ->get([
                'tie_codigo',
                'tie_nombre',
                'tie_telefono',
                'tie_horario',
                'tie_correo',
                'tie_direccion',
            ])
            ->map(fn (object $store): array => $this->mapStore($store, 'Tienda'));

        return $records->concat($physicalStores)->values()->all();
    }

    private function mapStore(object $store, string $type): array
    {
        return [
            'id' => trim((string) $store->tie_codigo),
            'nombre' => $type === 'Domicilio' ? 'Domicilio' : trim((string) $store->tie_nombre),
            'telefono' => trim((string) $store->tie_telefono),
            'horario' => trim((string) $store->tie_horario),
            'correo' => trim((string) $store->tie_correo),
            'direccion' => trim((string) $store->tie_direccion),
            'tipo' => $type,
        ];
    }
}
