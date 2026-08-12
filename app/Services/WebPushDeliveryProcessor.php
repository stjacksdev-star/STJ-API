<?php

namespace App\Services;

use App\Models\WebPushDelivery;
use App\Models\WebPushEvent;
use App\Models\WebPushSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class WebPushDeliveryProcessor
{
    public function __construct(private readonly FirebasePushService $firebase) {}

    public function process(?int $deliveryId = null, int $limit = 100, ?CarbonImmutable $processedAt = null): array
    {
        $processedAt ??= CarbonImmutable::now();
        $summary = $this->summary();
        $summary['stale_failed'] = $this->failStaleProcessing($processedAt);
        $ids = WebPushDelivery::query()
            ->whereIn('pen_estado', ['PENDIENTE', 'REINTENTO'])
            ->where('pen_disponible_en', '<=', $processedAt)
            ->when($deliveryId !== null, fn ($query) => $query->whereKey($deliveryId))
            ->orderBy('pen_disponible_en')
            ->orderBy('pen_id')
            ->limit($deliveryId !== null ? 1 : max(1, min($limit, 1000)))
            ->pluck('pen_id');

        $summary['pending'] = $ids->count();

        foreach ($ids as $id) {
            $delivery = $this->claim((int) $id, $processedAt);
            if (! $delivery) {
                $summary['skipped']++;

                continue;
            }

            $result = $this->processClaimed($delivery, $processedAt);
            $summary[$result]++;
        }

        return $summary;
    }

    private function claim(int $id, CarbonImmutable $now): ?WebPushDelivery
    {
        $worker = gethostname().':'.getmypid();
        $claimed = WebPushDelivery::query()
            ->whereKey($id)
            ->whereIn('pen_estado', ['PENDIENTE', 'REINTENTO'])
            ->where('pen_disponible_en', '<=', $now)
            ->update([
                'pen_estado' => 'PROCESANDO',
                'pen_bloqueado_en' => $now,
                'pen_bloqueado_por' => mb_substr($worker, 0, 100),
                'pen_actualizado_en' => $now,
            ]);

        return $claimed === 1 ? WebPushDelivery::query()->find($id) : null;
    }

    private function processClaimed(WebPushDelivery $delivery, CarbonImmutable $now): string
    {
        $invalidReason = $this->validate($delivery, $now);
        if ($invalidReason !== null) {
            $this->finish($delivery, 'CANCELADO', $now, error: $invalidReason);

            return 'cancelled';
        }

        $subscription = WebPushSubscription::query()->find($delivery->pen_suscripcion_id);
        if (! $subscription
            || $subscription->psu_plataforma !== 'WEB'
            || $subscription->psu_estado !== 'ACTIVA'
            || $subscription->psu_permiso !== 'GRANTED') {
            $this->finish($delivery, 'DESCARTADO', $now, error: 'La suscripcion WEB ya no esta activa o autorizada.');

            return 'discarded';
        }

        try {
            $summary = $this->firebase->sendToTokens(
                [(string) $subscription->psu_token],
                (string) $delivery->pen_titulo,
                (string) $delivery->pen_cuerpo,
                [
                    'click_action' => $this->clickUrl($delivery),
                    'image' => (string) ($delivery->pen_imagen ?? ''),
                    'delivery_id' => (string) $delivery->getKey(),
                    'automation' => (string) data_get($delivery->pen_payload, 'automation', ''),
                    'stage' => (string) $delivery->pen_stage,
                ],
            );
            $result = (string) data_get($summary, 'results.0.result', 'Firebase no devolvio resultado.');

            if ((int) ($summary['sent'] ?? 0) === 1) {
                DB::transaction(function () use ($delivery, $result, $now) {
                    $this->finish($delivery, 'ENVIADO', $now, result: $result, sentAt: $now, attempted: true);
                    $this->event($delivery, 'SENT', $now, ['firebase_result' => $result]);
                });

                return 'sent';
            }

            if ($this->firebase->isInvalidTokenResult($result)) {
                DB::transaction(function () use ($delivery, $subscription, $result, $now) {
                    $subscription->forceFill(['psu_estado' => 'INVALIDA', 'psu_actualizado_en' => $now])->save();
                    $this->finish($delivery, 'DESCARTADO', $now, error: $result, attempted: true);
                    $this->event($delivery, 'INVALID_TOKEN', $now, ['firebase_result' => $result]);
                });

                return 'invalid';
            }

            return $this->retryOrFail($delivery, $result, $now);
        } catch (Throwable $exception) {
            return $this->retryOrFail($delivery, $exception->getMessage(), $now);
        }
    }

    private function validate(WebPushDelivery $delivery, CarbonImmutable $now): ?string
    {
        if ($delivery->pen_entidad_tipo !== 'CART') {
            return 'Tipo de entidad no soportado en esta fase.';
        }

        $cart = DB::table('stj_carritos')->where('car_id', $delivery->pen_entidad_id)->first();
        if (! $cart) {
            return 'El carrito ya no existe.';
        }
        if ($cart->car_estado !== 'ACTIVO' || $cart->car_pedido_id !== null || $cart->car_convertido_en !== null) {
            return 'El carrito ya no esta activo o fue convertido.';
        }
        if ((int) $cart->car_version !== (int) $delivery->pen_entidad_version) {
            return 'El carrito cambio despues de crear la entrega.';
        }
        if ((int) $cart->car_pais_id !== (int) $delivery->pen_pais_id
            || (int) $cart->car_visitante_id !== (int) $delivery->pen_visitante_id
            || ($cart->car_usu_id === null ? null : (int) $cart->car_usu_id) !== ($delivery->pen_usu_id === null ? null : (int) $delivery->pen_usu_id)) {
            return 'La identidad o el pais del carrito ya no coincide.';
        }
        if (CarbonImmutable::parse($cart->car_expira_en)->lte($now)) {
            return 'El carrito ya expiro.';
        }
        if (! DB::table('stj_carrito_detalles')->where('cad_carrito_id', $cart->car_id)->exists()) {
            return 'El carrito esta vacio.';
        }

        return null;
    }

    private function retryOrFail(WebPushDelivery $delivery, string $error, CarbonImmutable $now): string
    {
        $attempts = (int) $delivery->pen_intentos + 1;
        $state = $attempts >= $this->maxAttempts() ? 'ERROR' : 'REINTENTO';
        $availableAt = $state === 'REINTENTO'
            ? $now->addMinutes($this->baseRetryMinutes() * (2 ** ($attempts - 1)))
            : $delivery->pen_disponible_en;

        $delivery->forceFill([
            'pen_estado' => $state,
            'pen_intentos' => $attempts,
            'pen_disponible_en' => $availableAt,
            'pen_ultimo_intento_en' => $now,
            'pen_error' => $this->short($error),
            'pen_bloqueado_en' => null,
            'pen_bloqueado_por' => null,
            'pen_actualizado_en' => $now,
        ])->save();

        return $state === 'ERROR' ? 'failed' : 'retry';
    }

    private function finish(WebPushDelivery $delivery, string $state, CarbonImmutable $now, ?string $result = null, ?string $error = null, ?CarbonImmutable $sentAt = null, bool $attempted = false): void
    {
        $delivery->forceFill([
            'pen_estado' => $state,
            'pen_intentos' => (int) $delivery->pen_intentos + ($attempted ? 1 : 0),
            'pen_ultimo_intento_en' => $attempted ? $now : $delivery->pen_ultimo_intento_en,
            'pen_enviado_en' => $sentAt,
            'pen_resultado' => $result === null ? null : $this->short($result),
            'pen_error' => $error === null ? null : $this->short($error),
            'pen_bloqueado_en' => null,
            'pen_bloqueado_por' => null,
            'pen_actualizado_en' => $now,
        ])->save();
    }

    private function event(WebPushDelivery $delivery, string $type, CarbonImmutable $now, array $data): void
    {
        WebPushEvent::query()->create([
            'pev_entrega_id' => $delivery->getKey(),
            'pev_event_uuid' => (string) Str::uuid(),
            'pev_tipo' => $type,
            'pev_origen' => 'WEB',
            'pev_datos' => $data,
            'pev_ocurrido_en' => $now,
            'pev_recibido_en' => $now,
        ]);
    }

    private function short(string $value): string
    {
        return mb_substr(trim($value), 0, 4000);
    }

    private function clickUrl(WebPushDelivery $delivery): string
    {
        $relative = URL::temporarySignedRoute(
            'storefront.push.click',
            now()->addDays((int) config('push_web.click_url_ttl_days', 30)),
            ['delivery' => $delivery->getKey()],
            absolute: false,
        );

        return rtrim((string) config('push_web.public_base_url'), '/').'/'.ltrim($relative, '/');
    }

    private function failStaleProcessing(CarbonImmutable $now): int
    {
        $timeout = max(1, (int) config('push_web.processing_timeout_minutes', 15));

        return WebPushDelivery::query()
            ->where('pen_estado', 'PROCESANDO')
            ->whereNotNull('pen_bloqueado_en')
            ->where('pen_bloqueado_en', '<=', $now->subMinutes($timeout))
            ->update([
                'pen_estado' => 'ERROR',
                'pen_error' => 'Procesamiento interrumpido; requiere revision manual para evitar un envio duplicado.',
                'pen_bloqueado_en' => null,
                'pen_bloqueado_por' => null,
                'pen_actualizado_en' => $now,
            ]);
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('push_web.max_attempts', 3));
    }

    private function baseRetryMinutes(): int
    {
        return max(1, (int) config('push_web.base_retry_minutes', 5));
    }

    private function summary(): array
    {
        return [
            'pending' => 0,
            'stale_failed' => 0,
            'sent' => 0,
            'retry' => 0,
            'failed' => 0,
            'invalid' => 0,
            'cancelled' => 0,
            'discarded' => 0,
            'skipped' => 0,
        ];
    }
}
