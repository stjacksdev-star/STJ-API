<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PushNotificationService
{
    /**
     * @return array{pending: int, sent: int, failed: int}
     */
    public function sendPending(): array
    {
        $serverKey = $this->serverKey();
        $now = now()->toDateTimeString();
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
            try {
                $result = $this->sendToFcm($notification, $serverKey);

                DB::table('stj_notificaciones_push_envios')
                    ->where('npe_id', $notification->npe_id)
                    ->update([
                        'npe_estado' => 'ENVIADO',
                        'npe_resultado' => serialize($result),
                    ]);

                $summary['sent']++;
            } catch (Throwable $throwable) {
                DB::table('stj_notificaciones_push_envios')
                    ->where('npe_id', $notification->npe_id)
                    ->update([
                        'npe_estado' => 'ERROR',
                        'npe_resultado' => $throwable->getMessage(),
                    ]);

                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function sendToFcm(object $notification, string $serverKey): string
    {
        $payload = [
            'notification' => [
                'title' => (string) $notification->npu_titulo,
                'body' => (string) $notification->npu_cuerpo,
                'image' => $this->imageUrl((string) $notification->npu_imagen),
                'icon' => $this->iconUrl(),
                'click_action' => (string) $notification->npu_action,
            ],
            'to' => (string) $notification->npu_para,
        ];

        $response = Http::timeout($this->timeout())
            ->withHeaders([
                'Authorization' => 'key='.$serverKey,
            ])
            ->asJson()
            ->post($this->url(), $payload);

        return $response->body();
    }

    private function url(): string
    {
        $url = trim((string) config('services.fcm.url'));

        if ($url === '') {
            throw new RuntimeException('FCM_API_URL no esta configurado.');
        }

        return $url;
    }

    private function serverKey(): string
    {
        $key = trim((string) config('services.fcm.server_key'));

        if ($key === '') {
            throw new RuntimeException('FCM_SERVER_KEY no esta configurado.');
        }

        return $key;
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.fcm.timeout', 30));
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

    private function iconUrl(): string
    {
        return trim((string) config('services.fcm.icon_url'));
    }
}
