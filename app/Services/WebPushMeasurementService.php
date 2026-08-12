<?php

namespace App\Services;

use App\Models\WebPushDelivery;
use App\Models\WebPushEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

class WebPushMeasurementService
{
    public function recordClick(WebPushDelivery $delivery, ?CarbonImmutable $occurredAt = null): bool
    {
        if ($delivery->pen_estado !== 'ENVIADO') {
            return false;
        }

        return $this->record($delivery, 'CLICK', $occurredAt ?? CarbonImmutable::now(), [
            'automation' => data_get($delivery->pen_payload, 'automation'),
            'stage' => $delivery->pen_stage,
        ]);
    }

    public function recordCartConverted(int $cartId, int $orderId, ?CarbonImmutable $occurredAt = null): int
    {
        if (! Schema::hasTable('stj_push_entregas') || ! Schema::hasTable('stj_push_eventos')) {
            return 0;
        }

        $occurredAt ??= CarbonImmutable::now();

        return WebPushDelivery::query()
            ->where('pen_entidad_tipo', 'CART')
            ->where('pen_entidad_id', $cartId)
            ->where('pen_estado', 'ENVIADO')
            ->get()
            ->sum(fn (WebPushDelivery $delivery) => (int) $this->record($delivery, 'CONVERTED', $occurredAt, [
                'order_id' => $orderId,
                'automation' => data_get($delivery->pen_payload, 'automation'),
                'stage' => $delivery->pen_stage,
            ]));
    }

    private function record(WebPushDelivery $delivery, string $type, CarbonImmutable $occurredAt, array $data): bool
    {
        if (! Schema::hasTable('stj_push_eventos')) {
            return false;
        }

        $event = WebPushEvent::query()->firstOrCreate(
            ['pev_event_uuid' => $this->eventUuid((int) $delivery->getKey(), $type)],
            [
                'pev_entrega_id' => $delivery->getKey(),
                'pev_tipo' => $type,
                'pev_origen' => 'WEB',
                'pev_datos' => $data,
                'pev_ocurrido_en' => $occurredAt,
                'pev_recibido_en' => CarbonImmutable::now(),
            ],
        );

        return $event->wasRecentlyCreated;
    }

    private function eventUuid(int $deliveryId, string $type): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "stj:push-event:{$deliveryId}:{$type}")->toString();
    }
}
