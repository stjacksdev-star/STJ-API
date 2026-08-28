<?php

namespace App\Services;

use App\Models\CustomerEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontPaymentEventService
{
    public function __construct(
        private ?StorefrontCouponOrderLifecycleService $couponLifecycle = null,
        private ?StorefrontPostPurchaseService $postPurchase = null,
    ) {}

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
            $orderOrigin = strtoupper((string) DB::table('stj_pedidos')->where('ped_id', $payment->ppa_pedido)->value('ped_origen'));
            $purchaseCreated = false;
            if ($status === 'APROBADA') {
                DB::table('stj_pedidos')
                    ->where('ped_id', $payment->ppa_pedido)
                    ->where('ped_estatus', 'PENDIENTE_PAGO')
                    ->update(['ped_estatus' => 'RECIBIDO']);
                $cart = DB::table('stj_carritos')->where('car_pedido_id', $payment->ppa_pedido)->first();
                if ($cart?->car_usu_id) {
                    StorefrontRecommendationService::forgetPurchaseHistory((int) $cart->car_usu_id, (int) $cart->car_pais_id);
                }
                if ($cart && ! DB::table('stj_cliente_eventos')->where('cev_pedido_id', $payment->ppa_pedido)->where('cev_tipo', 'PURCHASE')->exists()) {
                    CustomerEvent::query()->create(['cev_event_uuid' => $eventUuid, 'cev_visitante_id' => $cart->car_visitante_id, 'cev_usu_id' => $cart->car_usu_id, 'cev_pais_id' => $cart->car_pais_id, 'cev_carrito_id' => $cart->car_id, 'cev_pedido_id' => $payment->ppa_pedido, 'cev_tipo' => 'PURCHASE', 'cev_valor' => $payment->ppa_monto, 'cev_moneda' => $cart->car_moneda, 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now(), 'cev_recibido_en' => now(), 'cev_metadata' => $metadata + ['paymentId' => $paymentId]]);
                    $purchaseCreated = true;
                }
                ($this->couponLifecycle ?? app(StorefrontCouponOrderLifecycleService::class))->consumeApprovedOrder((int) $payment->ppa_pedido);
                if ($purchaseCreated) {
                    ($this->postPurchase ?? app(StorefrontPostPurchaseService::class))->schedule((int) $payment->ppa_pedido, $paymentId);
                }
            } elseif (in_array($status, ['DENEGADA', 'TIMEOUT', 'ERROR', 'ANULADO', 'REVERSION', 'DEVOLUCION'], true)) {
                if (in_array($status, ['DENEGADA', 'TIMEOUT', 'ERROR'], true)) {
                    DB::table('stj_carritos')
                        ->where('car_pedido_id', $payment->ppa_pedido)
                        ->where('car_estado', 'CONVERTIDO')
                        ->update([
                            'car_estado' => 'ACTIVO',
                            'car_checkout_en' => null,
                            'car_convertido_en' => null,
                            'car_actualizado_en' => now(),
                        ]);
                    if ($orderOrigin === 'APP') {
                        $this->restoreMobileCart((int) $payment->ppa_pedido);
                    }
                }
                ($this->couponLifecycle ?? app(StorefrontCouponOrderLifecycleService::class))->closeUnapprovedOrder((int) $payment->ppa_pedido, $status);
            }

            return ['paymentId' => $paymentId, 'orderId' => (int) $payment->ppa_pedido, 'status' => $status, 'purchaseCreated' => $purchaseCreated];
        });
    }

    private function restoreMobileCart(int $orderId): void
    {
        $target = DB::table('stj_carritos')->where('car_pedido_id', $orderId)->lockForUpdate()->first();
        if (! $target) {
            return;
        }

        $others = DB::table('stj_carritos')
            ->where('car_id', '!=', $target->car_id)
            ->where('car_pais_id', $target->car_pais_id)
            ->where('car_estado', 'ACTIVO')
            ->when($target->car_usu_id, fn ($query) => $query->where('car_usu_id', $target->car_usu_id), fn ($query) => $query->whereNull('car_usu_id')->where('car_visitante_id', $target->car_visitante_id))
            ->lockForUpdate()
            ->get();

        foreach ($others as $source) {
            $lines = DB::table('stj_carrito_detalles')->where('cad_carrito_id', $source->car_id)->lockForUpdate()->get();
            foreach ($lines as $line) {
                $existing = DB::table('stj_carrito_detalles')
                    ->where('cad_carrito_id', $target->car_id)
                    ->where('cad_ref', $line->cad_ref)
                    ->where('cad_talla', $line->cad_talla)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    DB::table('stj_carrito_detalles')->where('cad_id', $existing->cad_id)->update([
                        'cad_cantidad' => min(99, (int) $existing->cad_cantidad + (int) $line->cad_cantidad),
                        'cad_seleccionado' => (bool) $existing->cad_seleccionado || (bool) $line->cad_seleccionado,
                        'cad_actualizado_en' => now(),
                    ]);
                    DB::table('stj_carrito_detalles')->where('cad_id', $line->cad_id)->delete();
                } else {
                    DB::table('stj_carrito_detalles')->where('cad_id', $line->cad_id)->update([
                        'cad_carrito_id' => $target->car_id,
                        'cad_actualizado_en' => now(),
                    ]);
                }
            }
            DB::table('stj_carritos')->where('car_id', $source->car_id)->update([
                'car_estado' => 'MERGED',
                'car_actualizado_en' => now(),
            ]);
        }

        DB::table('stj_carritos')->where('car_id', $target->car_id)->update([
            'car_pedido_id' => null,
            'car_estado' => 'ACTIVO',
            'car_checkout_en' => null,
            'car_convertido_en' => null,
            'car_version' => (int) $target->car_version + 1,
            'car_actualizado_en' => now(),
        ]);
    }
}
