<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PushNotificationService
{
    private const DASHBOARD_TIMEZONE = 'America/El_Salvador';

    public function __construct(
        private readonly FirebasePushService $firebase,
    ) {}

    /**
     * @return array{pending: int, sent: int, failed: int}
     */
    public function sendPending(): array
    {
        $now = Carbon::now(self::DASHBOARD_TIMEZONE)->toDateTimeString();
        $summary = [
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        $notifications = DB::table('stj_notificaciones_push_envios')
            ->join('stj_notificaciones_push', 'npe_notificacion', '=', 'npu_id')
            ->leftJoin('stj_promociones', 'npu_promocion', '=', 'prm_id')
            ->where('npe_fecha_envio', '<=', $now)
            ->where('npe_estado', 'PENDIENTE')
            ->select('stj_notificaciones_push_envios.*', 'stj_notificaciones_push.*', 'stj_promociones.*')
            ->orderBy('npe_fecha_envio')
            ->orderBy('npe_id')
            ->get();

        $summary['pending'] = $notifications->count();

        foreach ($notifications as $notification) {
            if (! $this->isStillPending((int) $notification->npe_id)) {
                continue;
            }

            try {
                $result = $this->sendToFcm($notification);

                DB::table('stj_notificaciones_push_envios')
                    ->where('npe_id', $notification->npe_id)
                    ->where('npe_estado', 'PENDIENTE')
                    ->update([
                        'npe_estado' => 'ENVIADO',
                        'npe_resultado' => $this->storedResult($result),
                    ]);

                $summary['sent']++;
            } catch (Throwable $throwable) {
                DB::table('stj_notificaciones_push_envios')
                    ->where('npe_id', $notification->npe_id)
                    ->where('npe_estado', 'PENDIENTE')
                    ->update([
                        'npe_estado' => 'ERROR',
                        'npe_resultado' => $this->storedResult($throwable->getMessage()),
                    ]);

                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function sendToFcm(object $notification): string
    {
        $data = [
            'click_action' => (string) $notification->npu_action,
            'image' => $this->imageUrl((string) $notification->npu_imagen),
        ];
        $platform = (string) ($notification->npu_plataforma ?? 'WEB');
        $topic = trim((string) $notification->npu_para);

        if ($topic !== '') {
            $data['topic'] = $topic;
        }

        if ($this->usesTokens($platform)) {
            $result = $this->firebase->sendToPlatform(
                $platform,
                (string) $notification->npu_titulo,
                (string) $notification->npu_cuerpo,
                $data,
            );

            return json_encode([
                'target' => 'platform',
                'platform' => $platform,
                'topic' => $topic,
                'sent' => $result['sent'],
                'failed' => $result['failed'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if ($topic === '') {
            $result = $this->firebase->sendToPlatform(
                'Todo',
                (string) $notification->npu_titulo,
                (string) $notification->npu_cuerpo,
                $data,
            );

            return json_encode([
                'target' => 'platform',
                'platform' => 'Todo',
                'sent' => $result['sent'],
                'failed' => $result['failed'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $this->firebase->sendToTopic(
            $topic,
            (string) $notification->npu_titulo,
            (string) $notification->npu_cuerpo,
            $data,
        );
    }

    private function imageUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/images/notificaciones_push/') && filled(config('filesystems.disks.spaces.url'))) {
            return rtrim((string) config('filesystems.disks.spaces.url'), '/').'/'.ltrim($path, '/');
        }

        return rtrim((string) config('services.fcm.image_base_url', 'https://stjacks.com'), '/').'/'.ltrim($path, '/');
    }

    private function storedResult(string $result): string
    {
        $result = trim($result);

        return mb_strlen($result) > 240 ? mb_substr($result, 0, 240) : $result;
    }

    private function usesTokens(string $platform): bool
    {
        return in_array(strtolower(trim($platform)), ['todo', 'android', 'ios', 'web'], true);
    }

    private function isStillPending(int $shipmentId): bool
    {
        return DB::table('stj_notificaciones_push_envios')
            ->where('npe_id', $shipmentId)
            ->where('npe_estado', 'PENDIENTE')
            ->exists();
    }
}
