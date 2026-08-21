<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StorefrontPostPurchaseIntegrationService
{
    public function dispatch(int $orderId, int $paymentId): void
    {
        if (! config('storefront_post_purchase.integrations_enabled')) {
            return;
        }

        $order = DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payment', 'payment.ppa_pedido', '=', 'orders.ped_id')
            ->join('stj_paises as country', 'country.pai_id', '=', 'orders.ped_id_pais')
            ->where('orders.ped_id', $orderId)->where('payment.ppa_id', $paymentId)
            ->first(['orders.ped_tienda', 'payment.ppa_ref', 'payment.ppa_estado', 'country.pai_codigo']);

        if (! $order || strtoupper((string) $order->ppa_estado) !== 'APROBADA') {
            return;
        }

        match (strtoupper((string) $order->pai_codigo)) {
            'GT' => $this->reserveGuatemalaItems($order),
            'HN' => $this->triggerHondurasPrism($orderId, $paymentId),
            default => null,
        };
    }

    private function reserveGuatemalaItems(object $order): void
    {
        if (! config('storefront_post_purchase.guatemala.enabled')) {
            return;
        }

        $template = trim((string) config('storefront_post_purchase.guatemala.url'));
        if ($template === '') {
            throw new RuntimeException('STOREFRONT_GT_ORDER_INTEGRATION_URL no está configurada.');
        }

        $items = DB::table('stj_pedidos_detalle')->where('car_ref', $order->ppa_ref)->where('car_accion', 'AGREGADO')->get();
        foreach ($items as $item) {
            $url = strtr($template, [
                '{store}' => rawurlencode((string) $order->ped_tienda),
                '{sku}' => rawurlencode((string) ($item->car_estilo_final ?: '')),
                '{size}' => rawurlencode((string) ($item->car_talla_final ?: $item->car_talla)),
                '{reference}' => rawurlencode((string) $order->ppa_ref),
                '{quantity}' => (string) ((int) $item->car_cantidad),
            ]);
            $this->client()->get($url)->throw();
        }
    }

    private function triggerHondurasPrism(int $orderId, int $paymentId): void
    {
        if (! config('storefront_post_purchase.honduras.enabled')) {
            return;
        }

        $url = trim((string) config('storefront_post_purchase.honduras.url'));
        if ($url === '') {
            throw new RuntimeException('STOREFRONT_HN_PRISM_URL no está configurada.');
        }

        $this->client()->get($url, ['ped_id' => $orderId, 'ppa_id' => $paymentId])->throw();
    }

    private function client()
    {
        return Http::connectTimeout(max(1, (int) config('storefront_post_purchase.connect_timeout', 2)))
            ->timeout(max(1, (int) config('storefront_post_purchase.timeout', 5)));
    }
}
