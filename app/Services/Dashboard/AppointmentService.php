<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        private readonly StjCitasClient $stjCitas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function catalog(?string $country = null): array
    {
        $countryId = filled($country) ? $this->resolveCountryId((string) $country) : null;

        return [
            'countries' => $this->countries(),
            'stores' => $countryId ? $this->stores($countryId) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function appointments(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $store = $this->resolveStore($countryId, $filters['store'] ?? null);

        if (! $store) {
            throw ValidationException::withMessages([
                'store' => 'Debe seleccionar una tienda.',
            ]);
        }

        $year = CarbonImmutable::now('America/El_Salvador')->year;
        $external = $this->stjCitas->appointments($countryId, $store['id'], $year);
        $rows = collect($external['rows'])
            ->map(fn (array $row) => $this->normalizeAppointment($row, $store, $countryId))
            ->values()
            ->all();

        return [
            'countries' => $this->countries(),
            'stores' => $this->stores($countryId),
            'filters' => [
                'country' => $countryId,
                'store' => $store['code'],
                'storeId' => $store['id'],
                'storeName' => $store['name'],
            ],
            'summary' => [
                'appointments' => count($rows),
                'emailsSent' => count(array_filter($rows, fn (array $row) => (bool) $row['emailSent'])),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->orderBy('pai_nombre')
            ->get()
            ->map(fn (object $country) => [
                'id' => (int) $country->pai_id,
                'code' => strtoupper((string) $country->pai_codigo),
                'name' => trim((string) $country->pai_nombre),
            ])
            ->values()
            ->all();
    }

    private function resolveCountryId(string $country): int
    {
        $country = trim($country);
        $query = DB::table('stj_paises')->select(['pai_id']);

        $resolved = is_numeric($country)
            ? $query->where('pai_id', (int) $country)->first()
            : $query->where('pai_codigo', strtoupper($country))->first();

        if (! $resolved) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return (int) $resolved->pai_id;
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private function resolveStore(int $countryId, mixed $store): ?array
    {
        $store = trim((string) $store);

        if ($store === '') {
            return null;
        }

        $query = DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId);

        $resolved = (clone $query)->where('tie_codigo', $store)->first();

        if (! $resolved && is_numeric($store)) {
            $resolved = (clone $query)->where('tie_id', (int) $store)->first();
        }

        if (! $resolved) {
            throw ValidationException::withMessages([
                'store' => 'La tienda seleccionada no existe para el pais indicado.',
            ]);
        }

        return [
            'id' => (int) $resolved->tie_id,
            'code' => (string) $resolved->tie_codigo,
            'name' => trim((string) $resolved->tie_nombre),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stores(int $countryId): array
    {
        return DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId)
            ->orderBy('tie_nombre')
            ->get()
            ->map(fn (object $store) => [
                'storeId' => (int) $store->tie_id,
                'storeCode' => (string) $store->tie_codigo,
                'store' => trim((string) $store->tie_nombre).' ('.(string) $store->tie_codigo.')',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAppointment(array $row, array $store, int $countryId): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'dui' => (string) $row['document'],
            'name' => trim((string) $row['name']),
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
            'registeredAt' => $row['registeredAt'],
            'schedule' => trim((string) $row['startTime']).' - '.trim((string) $row['endTime']),
            'day' => (string) $row['date'],
            'emailSent' => strtoupper((string) $row['emailStatus']) === 'ENVIADO',
            'store' => [
                'id' => $store['id'],
                'code' => $store['code'],
                'name' => $store['name'],
                'country' => $countryId,
            ],
        ];
    }
}
