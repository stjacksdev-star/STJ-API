<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
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
                $result = $this->sendToFcm($notification);

                DB::table('stj_notificaciones_push_envios')
                    ->where('npe_id', $notification->npe_id)
                    ->update([
                        'npe_estado' => 'ENVIADO',
                        'npe_resultado' => $this->storedResult($result),
                    ]);

                $summary['sent']++;
            } catch (Throwable $throwable) {
                DB::table('stj_notificaciones_push_envios')
                    ->where('npe_id', $notification->npe_id)
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
        $imageUrl = $this->imageUrl((string) $notification->npu_imagen);
        $action = (string) $notification->npu_action;

        $payload = [
            'message' => [
                'topic' => $this->topic((string) $notification->npu_para),
                'notification' => [
                    'title' => (string) $notification->npu_titulo,
                    'body' => (string) $notification->npu_cuerpo,
                    'image' => $imageUrl,
                ],
                'data' => [
                    'click_action' => $action,
                ],
                'android' => [
                    'notification' => [
                        'image' => $imageUrl,
                        'click_action' => $action,
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'mutable-content' => 1,
                        ],
                    ],
                    'fcm_options' => [
                        'image' => $imageUrl,
                    ],
                ],
                'webpush' => [
                    'notification' => [
                        'image' => $imageUrl,
                        'icon' => $this->iconUrl(),
                    ],
                    'fcm_options' => [
                        'link' => $action,
                    ],
                ],
            ],
        ];

        $response = Http::timeout($this->timeout())
            ->withToken($this->accessToken())
            ->asJson()
            ->post($this->url(), $payload);

        if ($response->failed()) {
            throw new RuntimeException($response->body());
        }

        return $response->body();
    }

    private function url(): string
    {
        $baseUrl = rtrim(trim((string) config('services.fcm.url')), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('FCM_API_URL no esta configurado.');
        }

        return "{$baseUrl}/projects/{$this->projectId()}/messages:send";
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm_http_v1_access_token', now()->addMinutes(50), function (): string {
            $serviceAccount = $this->serviceAccount();
            $now = time();
            $assertion = $this->jwt([
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $this->tokenUrl(),
                'iat' => $now,
                'exp' => $now + 3600,
            ], $serviceAccount['private_key']);

            $response = Http::asForm()
                ->timeout($this->timeout())
                ->post($this->tokenUrl(), [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

            if ($response->failed()) {
                throw new RuntimeException('No fue posible obtener access token FCM: '.$response->body());
            }

            $token = trim((string) $response->json('access_token'));

            if ($token === '') {
                throw new RuntimeException('Google no retorno access_token para FCM.');
            }

            return $token;
        });
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function jwt(array $claims, string $privateKey): string
    {
        $unsigned = $this->base64Url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR)).'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No fue posible firmar JWT para FCM.');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function serviceAccount(): array
    {
        $path = $this->serviceAccountPath();
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            throw new RuntimeException('FCM_SERVICE_ACCOUNT_JSON no contiene client_email/private_key validos.');
        }

        return [
            'client_email' => (string) $data['client_email'],
            'private_key' => (string) $data['private_key'],
        ];
    }

    private function serviceAccountPath(): string
    {
        $path = trim((string) config('services.fcm.service_account_json'));

        if ($path === '') {
            throw new RuntimeException('FCM_SERVICE_ACCOUNT_JSON no esta configurado.');
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $candidates = [
            $normalized,
            base_path($normalized),
            storage_path($normalized),
            storage_path('app'.DIRECTORY_SEPARATOR.$normalized),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("No existe el archivo FCM_SERVICE_ACCOUNT_JSON: {$path}");
    }

    private function projectId(): string
    {
        $projectId = trim((string) config('services.fcm.project_id'));

        if ($projectId === '') {
            throw new RuntimeException('FCM_PROJECT_ID no esta configurado.');
        }

        return $projectId;
    }

    private function tokenUrl(): string
    {
        $url = trim((string) config('services.fcm.token_url'));

        if ($url === '') {
            throw new RuntimeException('FCM_TOKEN_URL no esta configurado.');
        }

        return $url;
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

    private function topic(string $to): string
    {
        $to = trim($to);

        if (str_starts_with($to, '/topics/')) {
            return substr($to, 8);
        }

        return ltrim($to, '/');
    }

    private function storedResult(string $result): string
    {
        $result = trim($result);

        return mb_strlen($result) > 240 ? mb_substr($result, 0, 240) : $result;
    }
}
