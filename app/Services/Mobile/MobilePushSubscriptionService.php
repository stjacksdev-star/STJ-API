<?php

namespace App\Services\Mobile;

use App\Models\StorefrontCustomer;
use App\Models\WebPushSubscription;
use App\Services\PushTopicService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobilePushSubscriptionService
{
    public function __construct(private readonly PushTopicService $topics) {}

    /** @param array<string, mixed> $data */
    public function register(Request $request, array $data, ?StorefrontCustomer $customer = null): array
    {
        $platform = strtoupper((string) $data['platform']);
        $installationId = trim((string) $data['installationId']);
        $token = trim((string) ($data['token'] ?? ''));
        $environment = strtoupper((string) ($data['environment'] ?? 'PRODUCTION'));

        if ($platform === 'WEB') {
            return $this->response(null);
        }

        if ($token === '') {
            $existing = WebPushSubscription::query()
                ->where('psu_instalacion_uuid', $installationId)
                ->where('psu_entorno', $environment)
                ->first();
            if ($existing) {
                $permission = strtoupper((string) ($data['permission'] ?? 'DEFAULT'));
                $existing->forceFill([
                    'psu_permiso' => $permission,
                    'psu_estado' => $permission === 'DENIED' ? 'REVOCADA' : $existing->psu_estado,
                    'psu_revocado_en' => $permission === 'DENIED' ? now() : $existing->psu_revocado_en,
                    'psu_ultima_actividad_en' => now(),
                    'psu_actualizado_en' => now(),
                ])->save();
            }

            return $this->response($existing ?? null);
        }

        $requestedCountryId = (int) $data['countryId'];
        $countryId = $customer && filled($customer->usu_pais_registro)
            ? (int) $customer->usu_pais_registro
            : $requestedCountryId;

        $subscription = DB::transaction(function () use ($request, $data, $customer, $platform, $installationId, $token, $environment, $countryId) {
            $hash = hash('sha256', $token);
            $subscription = WebPushSubscription::query()
                ->where('psu_instalacion_uuid', $installationId)
                ->where('psu_entorno', $environment)
                ->lockForUpdate()
                ->first();

            $duplicate = WebPushSubscription::query()
                ->where('psu_token_hash', $hash)
                ->when($subscription, fn ($query) => $query->whereKeyNot($subscription->getKey()))
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                $duplicate->forceFill([
                    'psu_estado' => 'INVALIDA',
                    'psu_revocado_en' => now(),
                    'psu_actualizado_en' => now(),
                ])->save();
            }

            // El pais de una instalacion anonima se fija en su primer registro.
            $fixedCountryId = $subscription ? (int) $subscription->psu_pais_id : $countryId;
            // Al autenticar, usu_pais_registro pasa a ser la autoridad permanente.
            if ($customer && filled($customer->usu_pais_registro)) {
                $fixedCountryId = (int) $customer->usu_pais_registro;
            }

            $values = [
                'psu_visitante_id' => null,
                'psu_usu_id' => $customer?->getKey() ?: $subscription?->psu_usu_id,
                'psu_pais_id' => $fixedCountryId,
                'psu_token' => $token,
                'psu_token_hash' => $hash,
                'psu_plataforma' => $platform,
                'psu_estado' => 'ACTIVA',
                'psu_permiso' => strtoupper((string) ($data['permission'] ?? 'GRANTED')),
                'psu_dispositivo' => mb_substr((string) ($data['device'] ?? ''), 0, 100) ?: null,
                'psu_sistema_operativo' => mb_substr((string) ($data['operatingSystem'] ?? $platform), 0, 100),
                'psu_idioma' => mb_substr((string) ($data['language'] ?? ''), 0, 20) ?: null,
                'psu_zona_horaria' => mb_substr((string) ($data['timezone'] ?? ''), 0, 64) ?: null,
                'psu_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'psu_instalacion_uuid' => $installationId,
                'psu_app_version' => mb_substr((string) ($data['appVersion'] ?? ''), 0, 40) ?: null,
                'psu_app_build' => mb_substr((string) ($data['appBuild'] ?? ''), 0, 40) ?: null,
                'psu_entorno' => $environment,
                'psu_provider' => 'FCM',
                'psu_ultima_actividad_en' => now(),
                'psu_token_actualizado_en' => ! $subscription || $subscription->psu_token_hash !== $hash ? now() : $subscription->psu_token_actualizado_en,
                'psu_revocado_en' => null,
                'psu_actualizado_en' => now(),
            ];

            if ($subscription) {
                $subscription->forceFill($values)->save();
            } else {
                $subscription = WebPushSubscription::query()->create($values + ['psu_creado_en' => now()]);
            }

            $this->topics->syncAutomatic($subscription);

            return $subscription;
        });

        return $this->response($subscription);
    }

    public function attachCustomer(string $installationId, string $environment, StorefrontCustomer $customer): void
    {
        $installationId = trim($installationId);
        if ($installationId === '') {
            return;
        }

        DB::transaction(function () use ($installationId, $environment, $customer) {
            $subscription = WebPushSubscription::query()
                ->where('psu_instalacion_uuid', $installationId)
                ->where('psu_entorno', strtoupper($environment))
                ->where('psu_estado', 'ACTIVA')
                ->lockForUpdate()
                ->first();
            if (! $subscription) {
                return;
            }

            $subscription->forceFill([
                'psu_usu_id' => $customer->getKey(),
                'psu_pais_id' => filled($customer->usu_pais_registro)
                    ? (int) $customer->usu_pais_registro
                    : $subscription->psu_pais_id,
                'psu_ultima_actividad_en' => now(),
                'psu_actualizado_en' => now(),
            ])->save();
            $this->topics->syncAutomatic($subscription);
        });
    }

    public function detachCustomer(string $installationId, string $environment, StorefrontCustomer $customer): void
    {
        if (trim($installationId) === '') {
            return;
        }

        DB::transaction(function () use ($installationId, $environment, $customer) {
            $subscription = WebPushSubscription::query()
                ->where('psu_instalacion_uuid', trim($installationId))
                ->where('psu_entorno', strtoupper($environment))
                ->where('psu_usu_id', $customer->getKey())
                ->lockForUpdate()
                ->first();
            if (! $subscription) {
                return;
            }
            $subscription->forceFill([
                'psu_usu_id' => null,
                'psu_ultima_actividad_en' => now(),
                'psu_actualizado_en' => now(),
            ])->save();
            $this->topics->syncAutomatic($subscription);
        });
    }

    private function response(?WebPushSubscription $subscription): array
    {
        return [
            'resultado' => 'true',
            'subscriptionId' => $subscription ? (int) $subscription->getKey() : null,
            'platform' => $subscription?->psu_plataforma,
            'countryId' => $subscription ? (int) $subscription->psu_pais_id : null,
        ];
    }
}
