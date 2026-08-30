<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebPushSubscriptionService
{
    public function __construct(
        private readonly FirebasePushService $firebase,
        private readonly PushTopicService $topics,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $data, string $userAgent): array
    {
        $requestedCountryId = $this->countryId($countryCode);
        $token = trim((string) $data['token']);
        $hash = hash('sha256', $token);
        $now = now();

        $subscription = DB::transaction(function () use ($requestedCountryId, $visitor, $customer, $data, $userAgent, $token, $hash, $now) {
            $subscription = WebPushSubscription::query()->where('psu_token_hash', $hash)->lockForUpdate()->first();
            $countryId = $subscription ? (int) $subscription->psu_pais_id : $requestedCountryId;
            if ($customer && filled($customer->usu_pais_registro)) {
                $countryId = (int) $customer->usu_pais_registro;
            }
            $values = [
                'psu_visitante_id' => $visitor->getKey(),
                'psu_usu_id' => $customer?->getKey(),
                'psu_pais_id' => $countryId,
                'psu_token' => $token,
                'psu_token_hash' => $hash,
                'psu_plataforma' => 'WEB',
                'psu_estado' => 'ACTIVA',
                'psu_permiso' => 'GRANTED',
                'psu_navegador' => $this->nullable($data['browser'] ?? null, 100),
                'psu_dispositivo' => $this->nullable($data['device'] ?? null, 100),
                'psu_sistema_operativo' => $this->nullable($data['operating_system'] ?? null, 100),
                'psu_idioma' => $this->nullable($data['language'] ?? null, 20),
                'psu_zona_horaria' => $this->nullable($data['timezone'] ?? null, 64),
                'psu_user_agent' => mb_substr($userAgent, 0, 500),
                'psu_ultima_actividad_en' => $now,
                'psu_token_actualizado_en' => $now,
                'psu_revocado_en' => null,
                'psu_actualizado_en' => $now,
            ];

            if ($subscription) {
                $subscription->forceFill($values)->save();
            } else {
                $subscription = WebPushSubscription::query()->create($values + ['psu_creado_en' => $now]);
            }

            $this->topics->syncAutomatic($subscription);

            return $subscription;
        });

        return $this->payload($subscription);
    }

    public function revoke(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, string $token, string $permission): array
    {
        $countryId = $this->countryId($countryCode);
        $hash = hash('sha256', trim($token));
        $subscription = WebPushSubscription::query()
            ->where('psu_token_hash', $hash)
            ->where('psu_pais_id', $countryId)
            ->where(function ($query) use ($visitor, $customer) {
                $query->where('psu_visitante_id', $visitor->getKey());
                if ($customer) {
                    $query->orWhere('psu_usu_id', $customer->getKey());
                }
            })
            ->first();

        if (! $subscription) {
            return ['revoked' => false];
        }

        $subscription->forceFill([
            'psu_estado' => 'REVOCADA',
            'psu_permiso' => strtoupper($permission),
            'psu_revocado_en' => now(),
            'psu_ultima_actividad_en' => now(),
            'psu_actualizado_en' => now(),
        ])->save();

        return ['revoked' => true, 'id' => (int) $subscription->getKey(), 'status' => 'REVOCADA'];
    }

    /** @return array{sent: bool, invalid: bool, result: string} */
    public function sendTest(WebPushSubscription $subscription, string $title, string $body, string $action, ?string $image = null): array
    {
        if ($subscription->psu_plataforma !== 'WEB' || $subscription->psu_estado !== 'ACTIVA') {
            throw ValidationException::withMessages(['subscription' => 'La suscripcion WEB no esta activa.']);
        }

        $summary = $this->firebase->sendToTokens([(string) $subscription->psu_token], $title, $body, [
            'click_action' => $action,
            'image' => $image ?: '',
            'subscription_id' => (string) $subscription->getKey(),
        ]);
        $result = (string) ($summary['results'][0]['result'] ?? 'Firebase no devolvio resultado.');
        $invalid = ($summary['failed'] ?? 0) > 0 && $this->firebase->isInvalidTokenResult($result);

        if ($invalid) {
            $subscription->forceFill([
                'psu_estado' => 'INVALIDA',
                'psu_actualizado_en' => now(),
            ])->save();
        }

        return ['sent' => ($summary['sent'] ?? 0) === 1, 'invalid' => $invalid, 'result' => $result];
    }

    private function countryId(string $code): int
    {
        $id = DB::table('stj_paises')->where('pai_codigo', strtoupper($code))->value('pai_id');
        if ($id === null) {
            throw ValidationException::withMessages(['country' => 'El pais no es valido.']);
        }

        return (int) $id;
    }

    private function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function payload(WebPushSubscription $subscription): array
    {
        return [
            'id' => (int) $subscription->getKey(),
            'platform' => (string) $subscription->psu_plataforma,
            'status' => (string) $subscription->psu_estado,
            'permission' => strtolower((string) $subscription->psu_permiso),
            'country_id' => (int) $subscription->psu_pais_id,
            'last_activity_at' => $subscription->psu_ultima_actividad_en?->toISOString(),
        ];
    }
}
