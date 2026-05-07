<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
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
     * @param array<string, mixed> $filters
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

        $rows = DB::table('citas as cit')
            ->leftJoin('horarios as h', 'h.hor_id', '=', 'cit.horario_id')
            ->join('stj_tiendas as store', function ($join) use ($countryId) {
                $join->on('store.tie_id', '=', 'cit.tie_id')
                    ->where('store.tie_pais', '=', $countryId);
            })
            ->where('cit.tie_id', $store['id'])
            ->orderBy('h.dia')
            ->orderBy('h.horario')
            ->orderBy('cit.fecha_cita')
            ->selectRaw('
                cit.cita_id,
                cit.cliente_codigo,
                cit.cliente_nombre,
                cit.cliente_apellido,
                cit.cliente_correo,
                cit.cliente_dui,
                cit.cliente_telefono,
                cit.fecha_cita,
                cit.envio_correo,
                h.horario,
                h.dia,
                store.tie_id,
                store.tie_codigo,
                store.tie_nombre,
                store.tie_pais
            ')
            ->get()
            ->map(fn (object $row) => $this->normalizeAppointment($row))
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
    private function normalizeAppointment(object $row): array
    {
        $name = trim(trim((string) $row->cliente_nombre).' '.trim((string) $row->cliente_apellido));

        return [
            'id' => (int) $row->cita_id,
            'code' => (string) ($row->cliente_codigo ?? ''),
            'dui' => (string) ($row->cliente_dui ?? ''),
            'name' => $name,
            'phone' => (string) ($row->cliente_telefono ?? ''),
            'email' => (string) ($row->cliente_correo ?? ''),
            'registeredAt' => $this->dateTimeOrNull($row->fecha_cita),
            'schedule' => (string) ($row->horario ?? ''),
            'day' => (string) ($row->dia ?? ''),
            'emailSent' => (bool) ($row->envio_correo ?? false),
            'store' => [
                'id' => (int) $row->tie_id,
                'code' => (string) $row->tie_codigo,
                'name' => trim((string) $row->tie_nombre),
                'country' => (int) $row->tie_pais,
            ],
        ];
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
