<?php

namespace App\Services;

use App\Models\WebPushDelivery;
use Illuminate\Support\Facades\Schema;

class WebPushDeliveryCancellationService
{
    public function cancelStaleCartDeliveries(int $cartId, int $currentVersion, string $reason): int
    {
        if (! Schema::hasTable('stj_push_entregas')) {
            return 0;
        }

        return $this->pendingCartDeliveries($cartId)
            ->where(function ($query) use ($currentVersion) {
                $query->whereNull('pen_entidad_version')
                    ->orWhere('pen_entidad_version', '<>', $currentVersion);
            })
            ->update($this->cancelledValues($reason));
    }

    public function cancelAllPendingCartDeliveries(int $cartId, string $reason): int
    {
        if (! Schema::hasTable('stj_push_entregas')) {
            return 0;
        }

        return $this->pendingCartDeliveries($cartId)
            ->update($this->cancelledValues($reason));
    }

    private function pendingCartDeliveries(int $cartId)
    {
        return WebPushDelivery::query()
            ->where('pen_entidad_tipo', 'CART')
            ->where('pen_entidad_id', $cartId)
            ->whereIn('pen_estado', ['PENDIENTE', 'REINTENTO']);
    }

    private function cancelledValues(string $reason): array
    {
        return [
            'pen_estado' => 'CANCELADO',
            'pen_error' => mb_substr(trim($reason), 0, 4000),
            'pen_bloqueado_en' => null,
            'pen_bloqueado_por' => null,
            'pen_actualizado_en' => now(),
        ];
    }
}
