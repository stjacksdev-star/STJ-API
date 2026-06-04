<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
                'rec_pais',
                'rec_numero_gestion',
                'rec_fecha_registro',
                'rec_pedido',
                'rec_stj',
                'rec_cliente_nombre',
                'rec_cliente_correo',
                'rec_cliente_telefono',
                'rec_cliente_dui',
                'rec_tipo',
                'rec_tipo_otro',
                'rec_origen',
                'rec_origen_otro',
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

        if (filled($filters['country'] ?? null)) {
            $query->where('rec_pais', (int) $filters['country']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('rec_estado', $filters['status']);
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('rec_tipo', $filters['type']);
        }

        $this->applyDateFilters($query, $filters);
        $this->applySearchFilter($query, $filters);

        $claimRows = $query
            ->limit($limit)
            ->get();
        $photoGroups = $this->photosForClaims($claimRows->pluck('rec_id')->map(fn ($id) => (int) $id)->all());
        $claims = $claimRows
            ->map(fn (object $claim) => $this->normalize($claim, $photoGroups[(int) $claim->rec_id] ?? []))
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
     * @param array<string, mixed> $filters
     * @return array{filename: string, contents: string}
     */
    public function export(array $filters): array
    {
        $columns = Schema::getColumnListing('stj_reclamos');
        $query = DB::table('stj_reclamos')
            ->select($columns)
            ->orderBy('rec_fecha_registro')
            ->orderBy('rec_id');

        if (filled($filters['country'] ?? null)) {
            $query->where('rec_pais', (int) $filters['country']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('rec_estado', $filters['status']);
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('rec_tipo', $filters['type']);
        }

        $this->applyDateFilters($query, $filters);
        $this->applySearchFilter($query, $filters);

        $rows = $query->get();
        $photoGroups = $this->photosForClaims($rows->pluck('rec_id')->map(fn ($id) => (int) $id)->all());
        $headers = [...$columns, 'fotos_urls'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reclamos');
        $sheet->fromArray($headers, null, 'A1');

        $rowNumber = 2;
        foreach ($rows as $row) {
            $values = [];

            foreach ($columns as $column) {
                $values[] = $row->{$column} ?? null;
            }

            $values[] = collect($photoGroups[(int) $row->rec_id] ?? [])
                ->pluck('url')
                ->implode("\n");

            $sheet->fromArray($values, null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->setAutoFilter('A1:'.$lastColumn.max(1, $rowNumber - 1));
        $sheet->freezePane('A2');

        $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);

        for ($columnIndex = 1; $columnIndex <= $lastColumnIndex; $columnIndex++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        $filename = 'reclamos-'.Carbon::parse($filters['startDate'])->format('Ymd').'-'.Carbon::parse($filters['endDate'])->format('Ymd').'.xlsx';
        $path = tempnam(sys_get_temp_dir(), 'stj-reclamos-');

        try {
            (new Xlsx($spreadsheet))->save($path);

            return [
                'filename' => $filename,
                'contents' => (string) file_get_contents($path),
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();

            if (is_file($path)) {
                unlink($path);
            }
        }
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

            $id = DB::table('stj_reclamos')->insertGetId($payload);
            $this->storePhotos($id, (string) $payload['rec_numero_gestion'], $data['photos'] ?? [], $data['actor'] ?? []);

            return $id;
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

        DB::transaction(function () use ($id, $data): void {
            DB::table('stj_reclamos')
                ->where('rec_id', $id)
                ->update($this->payload($data, true));

            $updated = DB::table('stj_reclamos')->where('rec_id', $id)->first();
            $this->storePhotos($id, (string) ($updated->rec_numero_gestion ?? $id), $data['photos'] ?? [], $data['actor'] ?? []);
        });

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

        return $this->normalize($claim, $this->photosForClaims([$id])[$id] ?? []);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyDateFilters($query, array $filters): void
    {
        if (filled($filters['startDate'] ?? null)) {
            $query->whereDate('rec_fecha_registro', '>=', Carbon::parse($filters['startDate'])->toDateString());
        }

        if (filled($filters['endDate'] ?? null)) {
            $query->whereDate('rec_fecha_registro', '<=', Carbon::parse($filters['endDate'])->toDateString());
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applySearchFilter($query, array $filters): void
    {
        if (! filled($filters['search'] ?? null)) {
            return;
        }

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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data, bool $updating): array
    {
        $payload = [
            'rec_numero_gestion' => $this->nullableString($data['managementNumber'] ?? null),
            'rec_pais' => (int) $data['country'],
            'rec_stj' => $this->nullableString($data['stj'] ?? null),
            'rec_cliente_nombre' => trim((string) $data['customerName']),
            'rec_cliente_correo' => $this->nullableString($data['customerEmail'] ?? null),
            'rec_cliente_telefono' => $this->nullableString($data['customerPhone'] ?? null),
            'rec_cliente_dui' => $this->nullableString($data['customerDui'] ?? null),
            'rec_tipo' => $data['type'] ?? 'otro',
            'rec_tipo_otro' => $this->isOther($data['type'] ?? null) ? $this->nullableString($data['typeOther'] ?? null) : null,
            'rec_origen' => $data['origin'] ?? 'web',
            'rec_origen_otro' => $this->isOther($data['origin'] ?? null) ? $this->nullableString($data['originOther'] ?? null) : null,
            'rec_responsable_area' => $data['responsibleArea'] ?? 'atencion_cliente',
            'rec_tienda' => $this->nullableString($data['store'] ?? null),
            'rec_descripcion' => trim((string) $data['description']),
            'rec_respuesta' => $this->nullableString($data['response'] ?? null),
            'rec_estado' => $data['status'] ?? 'recibido',
            'rec_motivo_rechazo' => $this->nullableString($data['rejectionReason'] ?? null),
            'rec_fecha_resolucion' => $this->nullableDateTime($data['resolvedAt'] ?? null),
            'rec_fecha_cierre' => $this->nullableDateTime($data['closedAt'] ?? null),
            'rec_usuario_asignado' => $this->nullableString($data['assignedTo'] ?? null),
        ];

        if (! $updating || array_key_exists('orderId', $data)) {
            $payload['rec_pedido'] = $this->nullableInt($data['orderId'] ?? null);
        }

        if (! $updating || array_key_exists('registeredBy', $data)) {
            $payload['rec_usuario_registro'] = $this->nullableString($data['registeredBy'] ?? null);
        }

        if (! $updating) {
            $payload['rec_fecha_registro'] = $this->nullableDateTime($data['registeredAt'] ?? null) ?? now()->format('Y-m-d H:i:s');
        } elseif (array_key_exists('registeredAt', $data)) {
            $payload['rec_fecha_registro'] = $this->nullableDateTime($data['registeredAt']);
        }

        return $payload;
    }

    private function normalize(object $claim, array $photos = []): array
    {
        return [
            'id' => (int) $claim->rec_id,
            'country' => $claim->rec_pais !== null ? (int) $claim->rec_pais : null,
            'managementNumber' => (string) $claim->rec_numero_gestion,
            'registeredAt' => $claim->rec_fecha_registro,
            'orderId' => $claim->rec_pedido !== null ? (int) $claim->rec_pedido : null,
            'stj' => $claim->rec_stj,
            'customerName' => (string) $claim->rec_cliente_nombre,
            'customerEmail' => $claim->rec_cliente_correo,
            'customerPhone' => $claim->rec_cliente_telefono,
            'customerDui' => $claim->rec_cliente_dui,
            'type' => (string) $claim->rec_tipo,
            'typeOther' => $claim->rec_tipo_otro ?? null,
            'typeLabel' => $this->isOther($claim->rec_tipo ?? null) && filled($claim->rec_tipo_otro ?? null)
                ? (string) $claim->rec_tipo_otro
                : $this->label((string) $claim->rec_tipo),
            'origin' => (string) $claim->rec_origen,
            'originOther' => $claim->rec_origen_otro ?? null,
            'originLabel' => $this->isOther($claim->rec_origen ?? null) && filled($claim->rec_origen_otro ?? null)
                ? (string) $claim->rec_origen_otro
                : $this->label((string) $claim->rec_origen),
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
            'registeredBy' => $claim->rec_usuario_registro,
            'assignedTo' => $claim->rec_usuario_asignado,
            'updatedAt' => $claim->rec_fecha_actualizacion ?? null,
            'photos' => $photos,
            'photosCount' => count($photos),
        ];
    }

    /**
     * @param array<int, int> $claimIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function photosForClaims(array $claimIds): array
    {
        if ($claimIds === []) {
            return [];
        }

        return DB::table('stj_reclamos_fotos')
            ->whereIn('rfo_reclamo', $claimIds)
            ->orderBy('rfo_orden')
            ->orderBy('rfo_id')
            ->get()
            ->groupBy('rfo_reclamo')
            ->map(fn ($rows) => $rows
                ->map(fn (object $photo) => [
                    'id' => (int) $photo->rfo_id,
                    'claimId' => (int) $photo->rfo_reclamo,
                    'url' => (string) $photo->rfo_url,
                    'originalName' => $photo->rfo_nombre_original,
                    'mime' => $photo->rfo_tipo,
                    'size' => $photo->rfo_peso_bytes !== null ? (int) $photo->rfo_peso_bytes : null,
                    'order' => (int) $photo->rfo_orden,
                    'registeredAt' => $photo->rfo_fecha_registro,
                    'registeredBy' => $photo->rfo_usuario_registro,
                ])
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param array<int, mixed> $photos
     * @param array<string, mixed> $actor
     */
    private function storePhotos(int $claimId, string $managementNumber, array $photos, array $actor): void
    {
        $photos = array_values(array_filter($photos, fn ($photo) => $photo instanceof UploadedFile));

        if ($photos === []) {
            return;
        }

        if (! $this->shouldStoreInSpaces()) {
            throw ValidationException::withMessages([
                'photos' => 'DigitalOcean Spaces no esta configurado para subir fotos de reclamos.',
            ]);
        }

        $nextOrder = (int) DB::table('stj_reclamos_fotos')
            ->where('rfo_reclamo', $claimId)
            ->max('rfo_orden') + 1;
        $folder = 'reclamos/'.Str::slug($managementNumber !== '' ? $managementNumber : (string) $claimId);

        foreach ($photos as $index => $photo) {
            $extension = strtolower($photo->getClientOriginalExtension() ?: $photo->extension() ?: 'jpg');
            $filename = now()->format('YmdHis').'-'.Str::random(10).'.'.$extension;
            $path = $folder.'/'.$filename;
            $mime = $photo->getMimeType() ?: 'image/jpeg';

            Storage::disk('spaces')->put($path, fopen($photo->getRealPath(), 'rb'), [
                'visibility' => 'public',
                'ContentType' => $mime,
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);

            DB::table('stj_reclamos_fotos')->insert([
                'rfo_reclamo' => $claimId,
                'rfo_url' => rtrim((string) config('filesystems.disks.spaces.url'), '/').'/'.$path,
                'rfo_nombre_original' => $photo->getClientOriginalName(),
                'rfo_tipo' => $mime,
                'rfo_peso_bytes' => $photo->getSize(),
                'rfo_orden' => $nextOrder + $index,
                'rfo_fecha_registro' => now(),
                'rfo_usuario_registro' => $this->actorLabel($actor),
            ]);
        }
    }

    private function shouldStoreInSpaces(): bool
    {
        return filled(config('filesystems.disks.spaces.key'))
            && filled(config('filesystems.disks.spaces.secret'))
            && filled(config('filesystems.disks.spaces.bucket'))
            && filled(config('filesystems.disks.spaces.endpoint'))
            && filled(config('filesystems.disks.spaces.url'));
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function actorLabel(array $actor): ?string
    {
        $label = trim((string) ($actor['username'] ?? $actor['email'] ?? $actor['name'] ?? ''));

        return $label !== '' ? $label : null;
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

    private function isOther(mixed $value): bool
    {
        return in_array(trim((string) $value), ['otro', 'otros'], true);
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
