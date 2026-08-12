<?php

namespace App\Services;

use App\Models\WebPushAutomation;
use App\Models\WebPushDelivery;
use App\Models\WebPushSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AbandonedCartPushEvaluator
{
    public const AUTOMATION_CODE = 'ABANDONED_CART';

    public const STAGE = 'PRIMARY';

    public function evaluate(?CarbonImmutable $evaluatedAt = null, int $limit = 500, bool $dryRun = false): array
    {
        $evaluatedAt ??= CarbonImmutable::now();
        $automation = WebPushAutomation::query()
            ->where('pau_codigo', self::AUTOMATION_CODE)
            ->where('pau_estado', 'ACTIVA')
            ->first();

        if (! $automation) {
            return $this->summary(['automation_missing' => 1]);
        }

        $cutoff = $evaluatedAt->subMinutes((int) $automation->pau_retraso_minutos);
        $summary = $this->summary();

        $carts = DB::table('stj_carritos as cart')
            ->join('stj_paises as country', 'country.pai_id', '=', 'cart.car_pais_id')
            ->where('cart.car_estado', 'ACTIVO')
            ->whereNull('cart.car_pedido_id')
            ->where('cart.car_ultima_actividad_en', '<=', $cutoff)
            ->where('cart.car_expira_en', '>', $evaluatedAt)
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('stj_carrito_detalles as item')
                ->whereColumn('item.cad_carrito_id', 'cart.car_id'))
            ->orderBy('cart.car_ultima_actividad_en')
            ->limit(max(1, min($limit, 5000)))
            ->get([
                'cart.car_id', 'cart.car_uuid', 'cart.car_visitante_id', 'cart.car_usu_id',
                'cart.car_pais_id', 'cart.car_version', 'cart.car_ultima_actividad_en',
                'country.pai_codigo',
            ]);

        foreach ($carts as $cart) {
            $summary['detected']++;

            if (! $this->countryIsEnabled($automation->pau_paises, (int) $cart->car_pais_id, (string) $cart->pai_codigo)) {
                $summary['country_filtered']++;

                continue;
            }

            $subscriptions = $this->subscriptionsFor($cart)->get();
            if ($subscriptions->isEmpty()) {
                $summary['without_subscription']++;

                continue;
            }

            foreach ($subscriptions as $subscription) {
                $summary['eligible']++;
                $key = $this->idempotencyKey((int) $cart->car_id, (int) $cart->car_version, (int) $subscription->psu_id);

                if (WebPushDelivery::query()->where('pen_idempotency_key', $key)->exists()) {
                    $summary['existing']++;

                    continue;
                }

                $deliveryScope = WebPushDelivery::query()
                    ->where('pen_automatizacion_id', $automation->getKey())
                    ->where('pen_suscripcion_id', $subscription->psu_id)
                    ->where('pen_entidad_tipo', 'CART')
                    ->where('pen_entidad_id', $cart->car_id)
                    ->whereNotIn('pen_estado', ['CANCELADO', 'DESCARTADO']);

                if ((clone $deliveryScope)
                    ->where('pen_creado_en', '>=', $evaluatedAt->subHours((int) $automation->pau_cooldown_horas))
                    ->exists()) {
                    $summary['cooldown']++;

                    continue;
                }

                if ((clone $deliveryScope)
                    ->where('pen_entidad_version', $cart->car_version)
                    ->count() >= (int) $automation->pau_maximo_por_entidad) {
                    $summary['maximum_reached']++;

                    continue;
                }

                if ($dryRun) {
                    $summary['would_create']++;

                    continue;
                }

                $values = $this->templateValues($cart);
                $scheduledAt = CarbonImmutable::parse($cart->car_ultima_actividad_en)
                    ->addMinutes((int) $automation->pau_retraso_minutos);
                $now = CarbonImmutable::now();

                $delivery = WebPushDelivery::query()->firstOrCreate(
                    ['pen_idempotency_key' => $key],
                    [
                        'pen_automatizacion_id' => $automation->getKey(),
                        'pen_suscripcion_id' => $subscription->psu_id,
                        'pen_visitante_id' => $cart->car_visitante_id,
                        'pen_usu_id' => $cart->car_usu_id,
                        'pen_pais_id' => $cart->car_pais_id,
                        'pen_entidad_tipo' => 'CART',
                        'pen_entidad_id' => $cart->car_id,
                        'pen_entidad_version' => $cart->car_version,
                        'pen_stage' => self::STAGE,
                        'pen_titulo' => $this->render((string) $automation->pau_titulo_plantilla, $values),
                        'pen_cuerpo' => $this->render((string) $automation->pau_cuerpo_plantilla, $values),
                        'pen_action' => $this->render((string) $automation->pau_action_plantilla, $values),
                        'pen_imagen' => $automation->pau_imagen,
                        'pen_payload' => [
                            'automation' => self::AUTOMATION_CODE,
                            'stage' => self::STAGE,
                            'cart_uuid' => $cart->car_uuid,
                            'cart_version' => (int) $cart->car_version,
                            'country' => strtolower((string) $cart->pai_codigo),
                            'evaluated_at' => $evaluatedAt->toIso8601String(),
                        ],
                        'pen_estado' => 'PENDIENTE',
                        'pen_intentos' => 0,
                        'pen_programado_en' => $scheduledAt,
                        'pen_disponible_en' => $scheduledAt,
                        'pen_creado_en' => $now,
                        'pen_actualizado_en' => $now,
                    ],
                );

                $summary[$delivery->wasRecentlyCreated ? 'created' : 'existing']++;
            }
        }

        return $summary;
    }

    public function idempotencyKey(int $cartId, int $cartVersion, int $subscriptionId): string
    {
        return sprintf('%s:%d:%d:%s:%d', self::AUTOMATION_CODE, $cartId, $cartVersion, self::STAGE, $subscriptionId);
    }

    public function render(string $template, array $values): string
    {
        return strtr($template, collect($values)->mapWithKeys(fn ($value, $key) => ['{'.$key.'}' => (string) $value])->all());
    }

    public function countryIsEnabled(?array $countries, int $countryId, string $countryCode): bool
    {
        if ($countries === null || $countries === []) {
            return true;
        }

        $normalized = array_map(fn ($country) => strtoupper((string) $country), $countries);

        return in_array((string) $countryId, $normalized, true)
            || in_array(strtoupper($countryCode), $normalized, true);
    }

    private function subscriptionsFor(object $cart): Builder
    {
        return WebPushSubscription::query()
            ->where('psu_pais_id', $cart->car_pais_id)
            ->where('psu_plataforma', 'WEB')
            ->where('psu_estado', 'ACTIVA')
            ->where('psu_permiso', 'GRANTED')
            ->when(
                $cart->car_usu_id !== null,
                fn (Builder $query) => $query->where('psu_usu_id', $cart->car_usu_id),
                fn (Builder $query) => $query->whereNull('psu_usu_id')->where('psu_visitante_id', $cart->car_visitante_id),
            );
    }

    private function templateValues(object $cart): array
    {
        return [
            'cart_id' => (int) $cart->car_id,
            'cart_uuid' => (string) $cart->car_uuid,
            'cart_version' => (int) $cart->car_version,
            'country' => strtolower((string) $cart->pai_codigo),
        ];
    }

    private function summary(array $overrides = []): array
    {
        return array_replace([
            'automation_missing' => 0,
            'detected' => 0,
            'country_filtered' => 0,
            'without_subscription' => 0,
            'eligible' => 0,
            'existing' => 0,
            'cooldown' => 0,
            'maximum_reached' => 0,
            'would_create' => 0,
            'created' => 0,
        ], $overrides);
    }
}
