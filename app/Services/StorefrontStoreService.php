<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontStoreService
{
    public function forCountry(string $countryCode, ?float $latitude = null, ?float $longitude = null): array
    {
        $country = DB::table('stj_paises')->where('pai_codigo', strtoupper($countryCode))->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['country' => 'Pais no soportado.']);
        }

        $now = now();
        $stores = DB::table('stj_tiendas')
            ->where('tie_pais', $country->pai_id)->where('tie_productos', 1)
            ->orderBy('tie_nombre')
            ->get(['tie_id', 'tie_codigo', 'tie_nombre', 'tie_direccion', 'tie_zona', 'tie_latitud', 'tie_longitud', 'tie_horario'])
            ->map(fn (object $store) => $this->normalizeStore($store, (int) $country->pai_id, $now, $latitude, $longitude))
            ->sortBy(fn (array $store) => [$store['distanceKm'] ?? PHP_FLOAT_MAX, $store['name']], SORT_REGULAR)
            ->values()->all();

        $code = strtolower((string) $country->pai_codigo);
        $deliveryCode = config("inventory.domicilio_store_by_country.{$code}");
        $services = [];
        if ($deliveryCode) {
            $services[] = ['type' => 'DOMICILIO', 'code' => (string) $deliveryCode, 'name' => 'Domicilio', 'headerLabel' => 'Domicilio', 'icon' => 'delivery'];
        }
        if ($stores !== []) {
            $services[] = ['type' => 'TIENDA', 'code' => null, 'name' => 'Recoger en tienda', 'headerLabel' => 'Seleccionar tienda', 'icon' => 'store'];
        }

        return ['country' => $code, 'services' => $services, 'stores' => $stores, 'locationApplied' => $latitude !== null && $longitude !== null];
    }

    private function normalizeStore(object $store, int $countryId, CarbonInterface $now, ?float $latitude, ?float $longitude): array
    {
        $storeLatitude = is_numeric($store->tie_latitud) ? (float) $store->tie_latitud : null;
        $storeLongitude = is_numeric($store->tie_longitud) ? (float) $store->tie_longitud : null;
        $distance = $latitude !== null && $longitude !== null && $storeLatitude !== null && $storeLongitude !== null
            ? $this->distance($latitude, $longitude, $storeLatitude, $storeLongitude) : null;

        return [
            'id' => (int) $store->tie_id, 'code' => trim((string) $store->tie_codigo), 'name' => trim((string) $store->tie_nombre),
            'address' => trim((string) $store->tie_direccion), 'zone' => trim((string) $store->tie_zona) ?: null,
            'latitude' => $storeLatitude, 'longitude' => $storeLongitude, 'distanceKm' => $distance,
            'distanceLabel' => $distance === null ? null : 'A '.number_format($distance, 1).' KM',
            'schedule' => $this->schedule($countryId, (string) $store->tie_codigo, $now, (string) $store->tie_horario),
        ];
    }

    private function schedule(int $countryId, string $storeCode, CarbonInterface $now, string $fallback): array
    {
        $rows = DB::table('stj_tiendas_horario')->where('tih_pais', $countryId)->where('tih_tienda', $storeCode)->get();
        $today = $rows->firstWhere('tih_dia', $now->dayOfWeek);
        if ($today && $today->tih_open === 'SI' && $today->tih_inicio && $today->tih_fin) {
            $start = $now->copy()->setTimeFromTimeString($today->tih_inicio);
            $end = $now->copy()->setTimeFromTimeString($today->tih_fin);
            if ($now->between($start, $end)) {
                return ['isOpen' => true, 'message' => 'Abierto hasta las '.$end->format('h:i A'), 'raw' => $fallback ?: null];
            }
            if ($now->lt($start)) {
                return ['isOpen' => false, 'message' => 'Tienda cerrada, abre hoy a las '.$start->format('h:i A'), 'raw' => $fallback ?: null];
            }
        }
        for ($offset = 1; $offset <= 7; $offset++) {
            $date = $now->copy()->addDays($offset);
            $next = $rows->firstWhere('tih_dia', $date->dayOfWeek);
            if ($next && $next->tih_open === 'SI' && $next->tih_inicio && $next->tih_fin) {
                return ['isOpen' => false, 'message' => 'Tienda cerrada, abre '.$this->dayLabel($offset).' de '.$this->time($next->tih_inicio).' a '.$this->time($next->tih_fin), 'raw' => $fallback ?: null];
            }
        }

        return ['isOpen' => null, 'message' => $fallback ? strip_tags(str_replace('<br/>', ' · ', $fallback)) : 'Horario no disponible', 'raw' => $fallback ?: null];
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371;
        $lat = deg2rad($lat2 - $lat1);
        $lon = deg2rad($lon2 - $lon1);
        $a = sin($lat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lon / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    private function time(string $time): string
    {
        return now()->setTimeFromTimeString($time)->format('h:i A');
    }

    private function dayLabel(int $offset): string
    {
        return $offset === 1 ? 'mañana' : 'el '.now()->addDays($offset)->locale('es')->dayName;
    }
}
