<?php

namespace App\Services\Dashboard;

use App\Services\Media\ImageOptimizer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PushNotificationMaintenanceService
{
    private const DASHBOARD_TIMEZONE = 'America/El_Salvador';

    public function __construct(
        private readonly ImageOptimizer $images,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(array $filters): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 1000), 5000));

        $query = DB::table('stj_notificaciones_push_envios as e')
            ->join('stj_notificaciones_push as n', 'e.npe_notificacion', '=', 'n.npu_id')
            ->leftJoin('stj_promociones as p', 'n.npu_promocion', '=', 'p.prm_id')
            ->select([
                'e.npe_id',
                'e.npe_notificacion',
                'e.npe_fecha_envio',
                'e.npe_estado',
                'e.npe_resultado',
                'n.npu_id',
                'n.npu_titulo',
                'n.npu_cuerpo',
                'n.npu_imagen',
                'n.npu_action',
                'n.npu_para',
                'n.npu_plataforma',
                'n.npu_promocion',
                'p.prm_nombre',
                'p.prm_nombre_comercial',
            ]);

        if (filled($filters['startDate'] ?? null)) {
            $query->where('e.npe_fecha_envio', '>=', $this->dashboardDateTime($filters['startDate'])->startOfDay()->toDateTimeString());
        }

        if (filled($filters['endDate'] ?? null)) {
            $query->where('e.npe_fecha_envio', '<=', $this->dashboardDateTime($filters['endDate'])->endOfDay()->toDateTimeString());
        }

        if (filled($filters['status'] ?? null) && strtoupper((string) $filters['status']) !== 'TODO') {
            $query->where('e.npe_estado', strtoupper((string) $filters['status']));
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['search'])).'%';

            $query->where(function ($query) use ($search) {
                $query->where('n.npu_titulo', 'like', $search)
                    ->orWhere('n.npu_cuerpo', 'like', $search)
                    ->orWhere('n.npu_para', 'like', $search)
                    ->orWhere('n.npu_action', 'like', $search);
            });
        }

        $notifications = $query
            ->orderByDesc('e.npe_fecha_envio')
            ->orderByDesc('e.npe_id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => $this->normalize($row))
            ->values()
            ->all();

        return [
            'options' => [
                'statuses' => ['TODO', 'PENDIENTE', 'ENVIADO', 'ERROR', 'CANCELADO'],
                'topics' => $this->topics(),
                'platforms' => $this->platforms(),
                'defaultPlatform' => 'Todo',
                'defaultTopic' => '',
            ],
            'notifications' => $notifications,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $image = null): array
    {
        $scheduledAt = $this->dashboardDateTime($data['scheduledAt'])->format('Y-m-d H:i:s');
        $imagePath = $image ? $this->storeImage($image) : trim((string) ($data['image'] ?? ''));

        return DB::transaction(function () use ($data, $scheduledAt, $imagePath) {
            $notificationId = DB::table('stj_notificaciones_push')->insertGetId([
                'npu_titulo' => trim((string) $data['title']),
                'npu_cuerpo' => trim((string) $data['body']),
                'npu_imagen' => $imagePath,
                'npu_action' => trim((string) $data['action']),
                'npu_para' => trim((string) ($data['to'] ?? '')),
                'npu_plataforma' => $this->platformValue((string) ($data['platform'] ?? 'Todo')),
                'npu_promocion' => filled($data['promotionId'] ?? null) ? (int) $data['promotionId'] : null,
            ]);

            $shipmentId = DB::table('stj_notificaciones_push_envios')->insertGetId([
                'npe_notificacion' => $notificationId,
                'npe_fecha_envio' => $scheduledAt,
                'npe_estado' => 'PENDIENTE',
                'npe_resultado' => null,
            ]);

            $row = DB::table('stj_notificaciones_push_envios as e')
                ->join('stj_notificaciones_push as n', 'e.npe_notificacion', '=', 'n.npu_id')
                ->leftJoin('stj_promociones as p', 'n.npu_promocion', '=', 'p.prm_id')
                ->select([
                    'e.npe_id',
                    'e.npe_notificacion',
                    'e.npe_fecha_envio',
                    'e.npe_estado',
                    'e.npe_resultado',
                    'n.npu_id',
                    'n.npu_titulo',
                    'n.npu_cuerpo',
                    'n.npu_imagen',
                    'n.npu_action',
                    'n.npu_para',
                    'n.npu_plataforma',
                    'n.npu_promocion',
                    'p.prm_nombre',
                    'p.prm_nombre_comercial',
                ])
                ->where('e.npe_id', $shipmentId)
                ->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'notification' => 'No fue posible recuperar la notificacion creada.',
                ]);
            }

            return $this->normalize($row);
        });
    }

    public function cancel(int $shipmentId): array
    {
        return DB::transaction(function () use ($shipmentId) {
            $row = $this->findShipment($shipmentId);

            if (! $row) {
                throw ValidationException::withMessages([
                    'notification' => 'La notificacion push no existe.',
                ]);
            }

            if (strtoupper((string) $row->npe_estado) !== 'PENDIENTE') {
                throw ValidationException::withMessages([
                    'notification' => 'Solo se pueden cancelar notificaciones pendientes.',
                ]);
            }

            DB::table('stj_notificaciones_push_envios')
                ->where('npe_id', $shipmentId)
                ->update([
                    'npe_estado' => 'CANCELADO',
                    'npe_resultado' => 'Cancelado desde dashboard',
                ]);

            $cancelled = $this->findShipment($shipmentId);

            if (! $cancelled) {
                throw ValidationException::withMessages([
                    'notification' => 'No fue posible recuperar la notificacion cancelada.',
                ]);
            }

            return $this->normalize($cancelled);
        });
    }

    private function findShipment(int $shipmentId): ?object
    {
        return DB::table('stj_notificaciones_push_envios as e')
            ->join('stj_notificaciones_push as n', 'e.npe_notificacion', '=', 'n.npu_id')
            ->leftJoin('stj_promociones as p', 'n.npu_promocion', '=', 'p.prm_id')
            ->select([
                'e.npe_id',
                'e.npe_notificacion',
                'e.npe_fecha_envio',
                'e.npe_estado',
                'e.npe_resultado',
                'n.npu_id',
                'n.npu_titulo',
                'n.npu_cuerpo',
                'n.npu_imagen',
                'n.npu_action',
                'n.npu_para',
                'n.npu_plataforma',
                'n.npu_promocion',
                'p.prm_nombre',
                'p.prm_nombre_comercial',
            ])
            ->where('e.npe_id', $shipmentId)
            ->first();
    }

    private function normalize(object $row): array
    {
        return [
            'id' => (int) $row->npe_id,
            'notificationId' => (int) $row->npu_id,
            'title' => (string) $row->npu_titulo,
            'body' => (string) $row->npu_cuerpo,
            'image' => (string) $row->npu_imagen,
            'action' => (string) $row->npu_action,
            'to' => (string) $row->npu_para,
            'platform' => (string) ($row->npu_plataforma ?? 'WEB'),
            'promotionId' => $row->npu_promocion !== null ? (int) $row->npu_promocion : null,
            'promotionName' => (string) ($row->prm_nombre_comercial ?: $row->prm_nombre ?: ''),
            'scheduledAt' => $row->npe_fecha_envio,
            'status' => (string) $row->npe_estado,
            'result' => $this->shortResult($row->npe_resultado),
        ];
    }

    private function shortResult(mixed $result): string
    {
        $result = trim((string) $result);

        if ($result === '') {
            return '';
        }

        return mb_strlen($result) > 600 ? mb_substr($result, 0, 600).'...' : $result;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function topics(): array
    {
        return [
            ['value' => '', 'label' => 'Todas las suscripciones activas'],
            ['value' => 'platform.ios', 'label' => 'Plataforma · IOS'],
            ['value' => 'platform.android', 'label' => 'Plataforma · Android'],
            ['value' => 'platform.web', 'label' => 'Plataforma · Web'],
            ['value' => 'country.sv', 'label' => 'Pais de registro · El Salvador'],
            ['value' => 'country.gt', 'label' => 'Pais de registro · Guatemala'],
            ['value' => 'country.hn', 'label' => 'Pais de registro · Honduras'],
            ['value' => 'country.cr', 'label' => 'Pais de registro · Costa Rica'],
            ['value' => 'customer.registered', 'label' => 'Clientes registrados'],
            ['value' => 'customer.guest', 'label' => 'Visitantes'],
            ['value' => 'journey.checkout', 'label' => 'Checkout activo'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function platforms(): array
    {
        return [
            ['value' => 'Todo', 'label' => 'Todo'],
            ['value' => 'Android', 'label' => 'Android'],
            ['value' => 'Ios', 'label' => 'Ios'],
            ['value' => 'WEB', 'label' => 'Web'],
        ];
    }

    private function platformValue(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'android' => 'Android',
            'ios' => 'Ios',
            'web' => 'WEB',
            default => 'Todo',
        };
    }

    private function dashboardDateTime(mixed $value): Carbon
    {
        return Carbon::parse((string) $value, self::DASHBOARD_TIMEZONE);
    }

    private function storeImage(UploadedFile $image): string
    {
        $optimized = $this->images->optimize($image);
        $filename = now()->format('YmdHis').'-'.Str::random(8).'.'.$optimized->extension;
        $path = "images/notificaciones_push/{$filename}";

        try {
            if ($this->shouldStoreInSpaces()) {
                Storage::disk('spaces')->put($path, fopen($optimized->path, 'rb'), [
                    'visibility' => 'public',
                    'ContentType' => $optimized->mime,
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ]);

                return '/'.$path;
            }

            $directory = public_path('images/notificaciones_push');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            copy($optimized->path, $directory.DIRECTORY_SEPARATOR.$filename);

            return "/images/notificaciones_push/{$filename}";
        } finally {
            if (is_file($optimized->path)) {
                unlink($optimized->path);
            }
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
}
