<?php

namespace App\Services\Prism;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrismPendingShipmentService
{
    public function register(int $orderId, int $paymentId): void
    {
        if (! config('storefront_post_purchase.honduras.register_pending')) {
            return;
        }

        DB::transaction(function () use ($orderId, $paymentId): void {
            // Serialize repeated callbacks for the same order; the unique country/reference
            // index also protects against conflicting references on different orders.
            $order = DB::table('stj_pedidos')->where('ped_id', $orderId)->lockForUpdate()->first();
            if (! $order || strtoupper((string) DB::table('stj_paises')
                ->where('pai_id', $order->ped_id_pais)->value('pai_codigo')) !== 'HN') {
                return;
            }
            $payment = DB::table('stj_pedidos_pago')->where('ppa_id', $paymentId)
                ->where('ppa_pedido', $orderId)->lockForUpdate()->first();
            if (! $payment || strtoupper((string) $payment->ppa_estado) !== 'APROBADA') {
                return;
            }
            $reference = (string) $payment->ppa_ref;
            if (trim($reference) === '' || strlen($reference) > 50) {
                throw new RuntimeException('Prism pendiente: referencia de pago inválida.');
            }

            $existing = DB::table('prism_envios')->where('pais_codigo', $order->ped_id_pais)
                ->where('stj_ref', $reference)->first();
            if ($existing) {
                if ((int) $existing->ped_id !== $orderId || (int) $existing->ppa_id !== $paymentId) {
                    throw new RuntimeException('Prism pendiente: referencia ya vinculada a otro pedido o pago.');
                }

                // Never reset a sent, failed or partially processed historical shipment.
                return;
            }

            $code = (string) $order->ped_tienda;
            $stores = DB::table('stj_tiendas')->where('tie_pais', $order->ped_id_pais)
                ->where('tie_codigo', $code)->limit(2)->get();
            if ($code === '' || $stores->count() !== 1 || (string) $stores[0]->tie_codigo !== $code) {
                throw new RuntimeException('Prism pendiente: tienda inexistente o ambigua para Honduras.');
            }
            $store = $stores[0];
            if (! preg_match('/^[1-9][0-9]*$/', (string) $store->prism_sid)
                || ! preg_match('/^[0-9]+$/', (string) $store->prism_store_number)
                || (int) $store->prism_store_number < 1) {
                throw new RuntimeException('Prism pendiente: tienda sin identificadores Prism válidos.');
            }

            DB::table('prism_envios')->insert([
                'ped_id' => $orderId,
                'ppa_id' => $paymentId,
                'stj_ref' => $reference,
                'pais_codigo' => $order->ped_id_pais,
                'tienda_codigo' => $code,
                'tienda_sid' => (string) $store->prism_sid,
                'integration_environment' => app()->environment(),
                'status' => 'pendiente',
                'intentos' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
