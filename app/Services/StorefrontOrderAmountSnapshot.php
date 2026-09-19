<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorefrontOrderAmountSnapshot
{
    /** Exact amounts are opt-in for new orders, never inferred for historical orders. */
    public function lines(string $reference, int $countryId): array
    {
        if (! Schema::hasTable('stj_carrito_operaciones') || ! Schema::hasTable('stj_carritos')) {
            return [];
        }

        $operations = DB::table('stj_pedidos_pago as payment')
            ->join('stj_pedidos as orders', 'orders.ped_id', '=', 'payment.ppa_pedido')
            ->join('stj_carritos as cart', 'cart.car_uuid', '=', 'orders.ped_sesion')
            ->join('stj_carrito_operaciones as operation', 'operation.cao_carrito_id', '=', 'cart.car_id')
            ->where('payment.ppa_ref', $reference)
            ->where('orders.ped_id_pais', $countryId)
            ->where('cart.car_pais_id', $countryId)
            ->where('operation.cao_tipo', 'ORDER_CREATE')
            ->orderByDesc('operation.cao_id')
            ->get(['operation.cao_respuesta', 'orders.ped_id', 'payment.ppa_monto_senv']);

        foreach ($operations as $operation) {
            $response = json_decode((string) $operation->cao_respuesta, true);
            $order = $response['order'] ?? null;
            if (! is_array($order) || ($order['lineAmountsVersion'] ?? null) !== 1
                || ($order['paymentRef'] ?? null) !== $reference
                || (int) ($order['pedidoId'] ?? 0) !== (int) $operation->ped_id) {
                continue;
            }

            $lines = [];
            $totalCents = 0;
            foreach ($order['items'] ?? [] as $item) {
                if (! is_array($item) || empty($item['detailId']) || isset($lines[$item['detailId']])
                    || ! isset($item['productId'], $item['sku'], $item['size'], $item['quantity'], $item['regularPrice'], $item['persistedDiscountPercentage'], $item['finalTotal'])
                    || ! is_numeric($item['finalTotal']) || (float) $item['finalTotal'] < 0) {
                    return [];
                }
                $lines[$item['detailId']] = $item;
                $totalCents += (int) round((float) $item['finalTotal'] * 100);
            }

            return $totalCents === (int) round((float) $operation->ppa_monto_senv * 100) ? $lines : [];
        }

        return [];
    }

    /** Use the saved amount only while the relevant line still matches its original terms. */
    public static function subtotal(object $line, ?array $snapshot, bool $billed = false): ?float
    {
        if ($snapshot === null) {
            return null;
        }
        $quantity = $billed ? ($line->car_total_facturado ?? 0) : ($line->car_cantidad ?? 0);
        $discount = $billed ? ($line->car_descuento_final ?? $line->car_descuento ?? 0) : ($line->car_descuento ?? 0);
        $sku = $billed ? ($line->car_estilo_final ?: $line->pro_codigo) : $line->pro_codigo;
        $size = $billed ? ($line->car_talla_final ?: $line->car_talla) : $line->car_talla;
        if ((int) $line->car_producto !== (int) $snapshot['productId']
            || (string) $sku !== (string) $snapshot['sku'] || (string) $size !== (string) $snapshot['size']
            || (int) $quantity !== (int) $snapshot['quantity']
            || (float) $line->car_precio !== (float) $snapshot['regularPrice']
            || (float) $discount !== (float) $snapshot['persistedDiscountPercentage']) {
            return null;
        }

        return round((float) $snapshot['finalTotal'], 2);
    }
}
