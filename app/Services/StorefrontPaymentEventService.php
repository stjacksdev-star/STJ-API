<?php

namespace App\Services;

use App\Models\CustomerEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontPaymentEventService
{
    public function record(int $paymentId, string $status, string $eventUuid, array $metadata = []): array
    {
        return DB::transaction(function () use ($paymentId, $status, $eventUuid, $metadata) {
            $status = strtoupper(trim($status));
            if (! in_array($status, ['PENDIENTE', 'APROBADA', 'DENEGADA', 'TIMEOUT', 'ERROR', 'ANULADO', 'REVERSION', 'DEVOLUCION'], true)) {
                throw ValidationException::withMessages(['payment_status' => 'Estado de pago no soportado.']);
            }
            $payment = DB::table('stj_pedidos_pago')->where('ppa_id', $paymentId)->lockForUpdate()->first();
            if (! $payment) {
                throw ValidationException::withMessages(['payment' => 'Intento de pago no encontrado.']);
            }
            if ($payment->ppa_estado === 'APROBADA' && $status !== 'APROBADA') {
                return ['paymentId' => $paymentId, 'orderId' => (int) $payment->ppa_pedido, 'status' => 'APROBADA', 'purchaseCreated' => false];
            }
            DB::table('stj_pedidos_pago')->where('ppa_id', $paymentId)->update(['ppa_estado' => $status, 'ppa_fecha_procesado' => now()]);
            $purchaseCreated = false;
            if ($status === 'APROBADA') {
                DB::table('stj_pedidos')
                    ->where('ped_id', $payment->ppa_pedido)
                    ->where('ped_estatus', 'PENDIENTE_PAGO')
                    ->update(['ped_estatus' => 'RECIBIDO']);
                $cart = DB::table('stj_carritos')->where('car_pedido_id', $payment->ppa_pedido)->first();
                if ($cart && ! DB::table('stj_cliente_eventos')->where('cev_pedido_id', $payment->ppa_pedido)->where('cev_tipo', 'PURCHASE')->exists()) {
                    CustomerEvent::query()->create(['cev_event_uuid' => $eventUuid, 'cev_visitante_id' => $cart->car_visitante_id, 'cev_usu_id' => $cart->car_usu_id, 'cev_pais_id' => $cart->car_pais_id, 'cev_carrito_id' => $cart->car_id, 'cev_pedido_id' => $payment->ppa_pedido, 'cev_tipo' => 'PURCHASE', 'cev_valor' => $payment->ppa_monto, 'cev_moneda' => $cart->car_moneda, 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now(), 'cev_recibido_en' => now(), 'cev_metadata' => $metadata + ['paymentId' => $paymentId]]);
                    $purchaseCreated = true;
                }
            }

            return ['paymentId' => $paymentId, 'orderId' => (int) $payment->ppa_pedido, 'status' => $status, 'purchaseCreated' => $purchaseCreated];
        });
    }
}
