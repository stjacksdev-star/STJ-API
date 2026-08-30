<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FirebasePushService
{
    public function isInvalidTokenResult(string $result): bool
    {
        $normalized = strtoupper($result);

        return str_contains($normalized, 'UNREGISTERED')
            || str_contains($normalized, 'REGISTRATION-TOKEN-NOT-REGISTERED')
            || str_contains($normalized, 'REQUESTED ENTITY WAS NOT FOUND');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): string
    {
        return $this->sendMessage([
            'topic' => $this->normalizeTopic($topic),
            ...$this->messagePayload($title, $body, $data),
        ]);
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, results: array<int, array{token: string, ok: bool, result: string}>}
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = $this->cleanTokens($tokens);
        $payload = $this->messagePayload($title, $body, $data);
        $accessToken = $this->accessToken();
        $url = $this->url();
        $summary = [
            'sent' => 0,
            'failed' => 0,
            'results' => [],
        ];

        foreach (array_chunk($tokens, $this->tokenChunkSize()) as $chunk) {
            try {
                $responses = Http::pool(function ($pool) use ($chunk, $payload, $accessToken, $url) {
                    return collect($chunk)
                        ->mapWithKeys(fn (string $token) => [
                            $token => $pool
                                ->withToken($accessToken)
                                ->asJson()
                                ->connectTimeout($this->connectTimeout())
                                ->timeout($this->sendTimeout())
                                ->post($url, [
                                    'message' => [
                                        'token' => $token,
                                        ...$payload,
                                    ],
                                ]),
                        ])
                        ->all();
                });
            } catch (Throwable $throwable) {
                foreach ($chunk as $token) {
                    $summary['failed']++;
                    $summary['results'][] = [
                        'token' => $token,
                        'ok' => false,
                        'result' => $this->shortResult($throwable->getMessage()),
                    ];
                }

                continue;
            }

            foreach ($responses as $token => $response) {
                if ($response instanceof Response && ! $response->failed()) {
                    $summary['sent']++;
                    $summary['results'][] = [
                        'token' => (string) $token,
                        'ok' => true,
                        'result' => $this->shortResult($response->body()),
                    ];

                    continue;
                }

                $summary['failed']++;
                $summary['results'][] = [
                    'token' => (string) $token,
                    'ok' => false,
                    'result' => $this->shortResult($response instanceof Response ? $response->body() : 'No response'),
                ];
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, results: array<int, array{token: string, ok: bool, result: string}>}
     */
    public function sendToPlatform(string $platform, string $title, string $body, array $data = []): array
    {
        return $this->sendToTokens(
            $this->tokensForPlatform($platform, $this->optionalTopic($data)),
            $title,
            $body,
            $data,
        );
    }

    /**
     * Envia a todas las suscripciones activas de un cliente, sin confiar en un usu_id recibido del frontend.
     *
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, results: array<int, array{token: string, ok: bool, result: string}>}
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        $tokens = DB::table('stj_push_suscripciones')
            ->where('psu_usu_id', $userId)
            ->where('psu_estado', 'ACTIVA')
            ->where('psu_permiso', 'GRANTED')
            ->whereNotNull('psu_token')
            ->where('psu_token', '<>', '')
            ->distinct()
            ->pluck('psu_token')
            ->all();

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function sendMessage(array $message): string
    {
        $response = Http::connectTimeout($this->connectTimeout())
            ->timeout($this->sendTimeout())
            ->withToken($this->accessToken())
            ->asJson()
            ->post($this->url(), ['message' => $message]);

        if ($response->failed()) {
            throw new RuntimeException($response->body());
        }

        return $response->body();
    }

    /**
     * @return array<int, string>
     */
    public function tokensForPlatform(string $platform, ?string $topic = null): array
    {
        $platforms = $this->platforms($platform);

        $topics = $this->topicVariants($topic);

        return DB::table('stj_push_suscripciones as s')
            ->whereIn('s.psu_plataforma', $platforms)
            ->where('s.psu_estado', 'ACTIVA')
            ->where('s.psu_permiso', 'GRANTED')
            ->whereNotNull('s.psu_token')
            ->where('s.psu_token', '<>', '')
            ->when($topics !== [], function ($query) use ($topics) {
                $normalized = collect($topics)
                    ->map(fn ($topic) => strtolower($this->normalizeTopic((string) $topic)))
                    ->unique()->values()->all();
                $query->whereExists(function ($subquery) use ($normalized) {
                    $subquery->selectRaw('1')
                        ->from('stj_push_suscripcion_topics as st')
                        ->join('stj_push_topics as t', 't.pto_id', '=', 'st.pst_topic_id')
                        ->whereColumn('st.pst_suscripcion_id', 's.psu_id')
                        ->where('t.pto_estado', 'ACTIVO')
                        ->whereIn('t.pto_codigo', $normalized)
                        ->where(function ($active) {
                            $active->whereNull('st.pst_expira_en')->orWhere('st.pst_expira_en', '>', now());
                        });
                });
            })
            ->distinct()
            ->pluck('s.psu_token')
            ->map(fn ($token) => trim((string) $token))
            ->filter(fn (string $token) => $token !== '' && $token !== '-')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function messagePayload(string $title, string $body, array $data): array
    {
        $action = (string) ($data['click_action'] ?? '');
        $imageUrl = (string) ($data['image'] ?? '');

        $payload = [
            'notification' => array_filter([
                'title' => $title,
                'body' => $body,
                'image' => $imageUrl !== '' ? $imageUrl : null,
            ], fn ($value) => $value !== null && $value !== ''),
            'data' => collect($data)
                ->except(['image', 'topic'])
                ->map(fn ($value) => (string) $value)
                ->all(),
        ];

        if ($imageUrl !== '' || $action !== '') {
            $payload['android'] = [
                'notification' => array_filter([
                    'image' => $imageUrl !== '' ? $imageUrl : null,
                    'click_action' => $action !== '' ? $action : null,
                ], fn ($value) => $value !== null && $value !== ''),
            ];
        }

        if ($imageUrl !== '') {
            $payload['apns'] = [
                'payload' => [
                    'aps' => [
                        'mutable-content' => 1,
                    ],
                ],
                'fcm_options' => [
                    'image' => $imageUrl,
                ],
            ];
        }

        if ($imageUrl !== '' || $action !== '') {
            $payload['webpush'] = [
                'notification' => array_filter([
                    'image' => $imageUrl !== '' ? $imageUrl : null,
                    'icon' => $this->iconUrl() ?: null,
                ], fn ($value) => $value !== null && $value !== ''),
                'fcm_options' => array_filter([
                    'link' => $action !== '' ? $action : null,
                ], fn ($value) => $value !== null && $value !== ''),
            ];
        }

        return $payload;
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

    private function sendTimeout(): int
    {
        return max(1, (int) config('services.fcm.send_timeout', 8));
    }

    private function connectTimeout(): int
    {
        return max(1, (int) config('services.fcm.connect_timeout', 3));
    }

    private function tokenChunkSize(): int
    {
        return max(1, min(200, (int) config('services.fcm.token_chunk_size', 50)));
    }

    private function iconUrl(): string
    {
        return trim((string) config('services.fcm.icon_url'));
    }

    private function normalizeTopic(string $topic): string
    {
        $topic = trim($topic);

        if (str_starts_with($topic, '/topics/')) {
            return substr($topic, 8);
        }

        return ltrim($topic, '/');
    }

    /**
     * @return array<int, string>
     */
    private function cleanTokens(array $tokens): array
    {
        return collect($tokens)
            ->map(fn ($token) => trim((string) $token))
            ->filter(fn (string $token) => $token !== '' && $token !== '-')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function platforms(string $platform): array
    {
        return match (strtolower(trim($platform))) {
            'todo', 'all' => ['ANDROID', 'IOS', 'WEB'],
            'ios' => ['IOS'],
            'web' => ['WEB'],
            default => ['ANDROID'],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalTopic(array $data): ?string
    {
        $topic = trim((string) ($data['topic'] ?? ''));

        return $topic === '' ? null : $topic;
    }

    /**
     * @return array<int, string>
     */
    private function topicVariants(?string $topic): array
    {
        $topic = trim((string) $topic);

        if ($topic === '') {
            return [];
        }

        $normalized = $this->normalizeTopic($topic);

        return collect([$topic, $normalized, '/topics/'.$normalized])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function shortResult(string $result): string
    {
        $result = trim($result);

        return mb_strlen($result) > 240 ? mb_substr($result, 0, 240) : $result;
    }
}
