<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimService
{
    public const TYPES = [
        'devolucion',
        'retracto',
        'reversion_pago',
        'garantia',
        'cambio_talla',
        'producto_incorrecto',
        'entrega',
        'otro',
    ];

    public const ORIGINS = [
        'web',
        'tienda',
        'domicilio',
        'whatsapp',
        'correo',
        'telefono',
        'otro',
    ];

    public const AREAS = [
        'atencion_cliente',
        'ecommerce',
        'tienda',
        'logistica',
        'finanzas',
        'mercadeo',
        'otro',
    ];

    public const STATUSES = [
        'recibido',
        'en_revision',
        'asignado',
        'en_proceso',
        'resuelto',
        'rechazado',
        'cerrado',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function index(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 500), 1000));

        $query = DB::table('stj_reclamos')
            ->select([
                'rec_id',
                'rec_numero_gestion',
                'rec_fecha_registro',
                'rec_pedido',
                'rec_stj',
                'rec_cliente_nombre',
                'rec_cliente_correo',
                'rec_cliente_telefono',
                'rec_cliente_dui',
                'rec_tipo',
                'rec_origen',
                'rec_responsable_area',
                'rec_tienda',
                'rec_descripcion',
                'rec_respuesta',
                'rec_estado',
                'rec_motivo_rechazo',
                'rec_fecha_resolucion',
                'rec_fecha_cierre',
                'rec_usuario_registro',
                'rec_usuario_asignado',
                'rec_fecha_actualizacion',
            ])
            ->orderByDesc('rec_fecha_registro')
            ->orderByDesc('rec_id');

        if (filled($filters['status'] ?? null)) {
            $query->where('rec_estado', $filters['status']);
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('rec_tipo', $filters['type']);
        }

        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(function ($nested) use ($search) {
                $nested
                    ->where('rec_numero_gestion', 'like', "%{$search}%")
                    ->orWhere('rec_stj', 'like', "%{$search}%")
                    ->orWhere('rec_cliente_nombre', 'like', "%{$search}%")
                    ->orWhere('rec_cliente_correo', 'like', "%{$search}%")
                    ->orWhere('rec_cliente_telefono', 'like', "%{$search}%")
                    ->orWhere('rec_cliente_dui', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $nested->orWhere('rec_pedido', (int) $search);
                }
            });
        }

        $claims = $query
            ->limit($limit)
            ->get()
            ->map(fn (object $claim) => $this->normalize($claim))
            ->values()
            ->all();

        return [
            'options' => [
                'types' => $this->options(self::TYPES),
                'origins' => $this->options(self::ORIGINS),
                'areas' => $this->options(self::AREAS),
                'statuses' => $this->options(self::STATUSES),
            ],
            'claims' => $claims,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): array
    {
        $id = DB::transaction(function () use ($data): int {
            $payload = $this->payload($data, false);

            if (! filled($payload['rec_numero_gestion'] ?? null)) {
                $payload['rec_numero_gestion'] = $this->nextManagementNumber();
            }

            return DB::table('stj_reclamos')->insertGetId($payload);
        });

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): array
    {
        $existing = DB::table('stj_reclamos')->where('rec_id', $id)->first();

        if (! $existing) {
            throw ValidationException::withMessages([
                'claim' => 'El reclamo seleccionado no existe.',
            ]);
        }

        DB::table('stj_reclamos')
            ->where('rec_id', $id)
            ->update($this->payload($data, true));

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $deleted = DB::table('stj_reclamos')->where('rec_id', $id)->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'claim' => 'El reclamo seleccionado no existe.',
            ]);
        }
    }

    private function find(int $id): array
    {
        $claim = DB::table('stj_reclamos')->where('rec_id', $id)->first();

        if (! $claim) {
            throw ValidationException::withMessages([
                'claim' => 'El reclamo seleccionado no existe.',
            ]);
        }

        return $this->normalize($claim);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data, bool $updating): array
    {
        $payload = [
            'rec_numero_gestion' => $this->nullableString($data['managementNumber'] ?? null),
            'rec_stj' => $this->nullableString($data['stj'] ?? null),
            'rec_cliente_nombre' => trim((string) $data['customerName']),
            'rec_cliente_correo' => $this->nullableString($data['customerEmail'] ?? null),
            'rec_cliente_telefono' => $this->nullableString($data['customerPhone'] ?? null),
            'rec_cliente_dui' => $this->nullableString($data['customerDui'] ?? null),
            'rec_tipo' => $data['type'] ?? 'otro',
            'rec_origen' => $data['origin'] ?? 'web',
            'rec_responsable_area' => $data['responsibleArea'] ?? 'atencion_cliente',
            'rec_tienda' => $this->nullableString($data['store'] ?? null),
            'rec_descripcion' => trim((string) $data['description']),
            'rec_respuesta' => $this->nullableString($data['response'] ?? null),
            'rec_estado' => $data['status'] ?? 'recibido',
            'rec_motivo_rechazo' => $this->nullableString($data['rejectionReason'] ?? null),
            'rec_fecha_resolucion' => $this->nullableDateTime($data['resolvedAt'] ?? null),
            'rec_fecha_cierre' => $this->nullableDateTime($data['closedAt'] ?? null),
            'rec_usuario_asignado' => $this->nullableInt($data['assignedTo'] ?? null),
        ];

        if (! $updating || array_key_exists('orderId', $data)) {
            $payload['rec_pedido'] = $this->nullableInt($data['orderId'] ?? null);
        }

        if (! $updating || array_key_exists('registeredBy', $data)) {
            $payload['rec_usuario_registro'] = $this->nullableInt($data['registeredBy'] ?? null);
        }

        if (! $updating) {
            $payload['rec_fecha_registro'] = $this->nullableDateTime($data['registeredAt'] ?? null) ?? now()->format('Y-m-d H:i:s');
        } elseif (array_key_exists('registeredAt', $data)) {
            $payload['rec_fecha_registro'] = $this->nullableDateTime($data['registeredAt']);
        }

        return $payload;
    }

    private function normalize(object $claim): array
    {
        return [
            'id' => (int) $claim->rec_id,
            'managementNumber' => (string) $claim->rec_numero_gestion,
            'registeredAt' => $claim->rec_fecha_registro,
            'orderId' => $claim->rec_pedido !== null ? (int) $claim->rec_pedido : null,
            'stj' => $claim->rec_stj,
            'customerName' => (string) $claim->rec_cliente_nombre,
            'customerEmail' => $claim->rec_cliente_correo,
            'customerPhone' => $claim->rec_cliente_telefono,
            'customerDui' => $claim->rec_cliente_dui,
            'type' => (string) $claim->rec_tipo,
            'typeLabel' => $this->label((string) $claim->rec_tipo),
            'origin' => (string) $claim->rec_origen,
            'originLabel' => $this->label((string) $claim->rec_origen),
            'responsibleArea' => (string) $claim->rec_responsable_area,
            'responsibleAreaLabel' => $this->label((string) $claim->rec_responsable_area),
            'store' => $claim->rec_tienda,
            'description' => (string) $claim->rec_descripcion,
            'response' => $claim->rec_respuesta,
            'status' => (string) $claim->rec_estado,
            'statusLabel' => $this->label((string) $claim->rec_estado),
            'rejectionReason' => $claim->rec_motivo_rechazo,
            'resolvedAt' => $claim->rec_fecha_resolucion,
            'closedAt' => $claim->rec_fecha_cierre,
            'registeredBy' => $claim->rec_usuario_registro !== null ? (int) $claim->rec_usuario_registro : null,
            'assignedTo' => $claim->rec_usuario_asignado !== null ? (int) $claim->rec_usuario_asignado : null,
            'updatedAt' => $claim->rec_fecha_actualizacion ?? null,
        ];
    }

    private function nextManagementNumber(): string
    {
        $prefix = 'STJ-REC-'.now()->format('Ymd').'-';
        $last = DB::table('stj_reclamos')
            ->where('rec_numero_gestion', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('rec_numero_gestion')
            ->value('rec_numero_gestion');

        $sequence = $last ? ((int) substr((string) $last, -6)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    /**
     * @param array<int, string> $values
     * @return array<int, array<string, string>>
     */
    private function options(array $values): array
    {
        return collect($values)
            ->map(fn (string $value) => [
                'value' => $value,
                'label' => $this->label($value),
            ])
            ->all();
    }

    private function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
