<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorefrontCouponOrderLifecycleService
{
    public function snapshot(int $cartId, int $orderId): void
    {
        if (! Schema::hasTable('stj_carrito_cupones') || ! Schema::hasTable('stj_pedido_cupones_aplicados')) {
            return;
        }

        $applications = DB::table('stj_carrito_cupones as a')
            ->join('stj_cupones as c', 'c.cup_id', '=', 'a.ccu_cupon_id')
            ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
            ->where('a.ccu_carrito_id', $cartId)
            ->where('a.ccu_estado', 'APLICADO')
            ->get([
                'a.*', 'c.cup_header', 'h.che_nombre', 'h.che_tipo', 'h.che_aplica', 'h.che_checkout',
                'h.che_pais', 'h.che_generico', 'h.che_multiple', 'h.che_aplica_promo', 'h.che_tipo_productos',
                'h.che_aplica_monto_minimo', 'h.che_monto_minimo', 'h.che_solo_primera_compra',
            ]);

        foreach ($applications as $application) {
            $productDiscount = (float) $application->ccu_descuento_productos;
            $shippingDiscount = (float) $application->ccu_descuento_envio;
            DB::table('stj_pedido_cupones_aplicados')->updateOrInsert(
                ['pca_pedido_id' => $orderId, 'pca_cupon_id' => $application->ccu_cupon_id],
                [
                    'pca_carrito_cupon_id' => $application->ccu_id,
                    'pca_header_id' => $application->cup_header,
                    'pca_codigo' => $application->ccu_codigo,
                    'pca_nombre' => $application->che_nombre,
                    'pca_tipo' => $application->che_tipo,
                    'pca_descuento_productos' => $productDiscount,
                    'pca_descuento_envio' => $shippingDiscount,
                    'pca_descuento_total' => round($productDiscount + $shippingDiscount, 2),
                    'pca_regla_snapshot' => json_encode([
                        'channel' => $application->che_aplica, 'checkout' => $application->che_checkout,
                        'countryId' => (int) $application->che_pais, 'generic' => $application->che_generico,
                        'multiple' => $application->che_multiple ?? 'NO', 'promotionRule' => $application->che_aplica_promo,
                        'productType' => $application->che_tipo_productos, 'minimumEnabled' => $application->che_aplica_monto_minimo,
                        'minimumAmount' => $application->che_monto_minimo, 'firstPurchaseOnly' => $application->che_solo_primera_compra,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'pca_aplicacion_snapshot' => json_encode([
                        'cartId' => $cartId, 'cartVersion' => (int) $application->ccu_carrito_version,
                        'email' => $application->ccu_correo_snapshot, 'checkout' => $application->ccu_checkout_snapshot,
                        'productDiscount' => $productDiscount, 'shippingDiscount' => $shippingDiscount,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'pca_estado' => 'APLICADO',
                    'pca_creado_en' => now(),
                    'pca_consumido_en' => null,
                    'pca_reversado_en' => null,
                ],
            );
        }
    }

    public function consumeApprovedOrder(int $orderId): void
    {
        if (! Schema::hasTable('stj_pedido_cupones_aplicados')) {
            return;
        }
        $approved = DB::table('stj_pedidos_pago')->where('ppa_pedido', $orderId)->where('ppa_estado', 'APROBADA')->exists();
        if (! $approved) {
            return;
        }

        $rows = DB::table('stj_pedido_cupones_aplicados')->where('pca_pedido_id', $orderId)->lockForUpdate()->get();
        foreach ($rows as $row) {
            DB::table('stj_pedido_cupones_aplicados')->where('pca_id', $row->pca_id)->update([
                'pca_estado' => 'CONSUMIDO', 'pca_consumido_en' => $row->pca_consumido_en ?: now(), 'pca_reversado_en' => null,
            ]);
            if ($row->pca_carrito_cupon_id) {
                DB::table('stj_carrito_cupones')->where('ccu_id', $row->pca_carrito_cupon_id)->update([
                    'ccu_estado' => 'CONSUMIDO', 'ccu_consumido_en' => now(), 'ccu_actualizado_en' => now(),
                ]);
            }
            $rule = DB::table('stj_cupones as c')->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
                ->where('c.cup_id', $row->pca_cupon_id)->first(['h.che_multiple', 'h.che_generico']);
            if (($rule->che_generico ?? 'NO') !== 'SI' && ($rule->che_multiple ?? 'NO') !== 'SI') {
                DB::table('stj_cupones')->where('cup_id', $row->pca_cupon_id)->update([
                    'cup_estado' => 'USADO', 'cup_fecha_utilizado' => now(), 'cup_disponible' => 0,
                ]);
            }
        }
    }

    public function closeUnapprovedOrder(int $orderId, string $paymentStatus): void
    {
        if (! Schema::hasTable('stj_pedido_cupones_aplicados')) {
            return;
        }
        if (DB::table('stj_pedidos_pago')->where('ppa_pedido', $orderId)->where('ppa_estado', 'APROBADA')->exists()) {
            return;
        }

        $rows = DB::table('stj_pedido_cupones_aplicados')->where('pca_pedido_id', $orderId)
            ->where('pca_estado', 'APLICADO')->lockForUpdate()->get();
        foreach ($rows as $row) {
            DB::table('stj_pedido_cupones_aplicados')->where('pca_id', $row->pca_id)->update([
                'pca_estado' => 'REVERSADO', 'pca_reversado_en' => now(),
            ]);
            if ($row->pca_carrito_cupon_id) {
                DB::table('stj_carrito_cupones')->where('ccu_id', $row->pca_carrito_cupon_id)->where('ccu_estado', 'APLICADO')->update([
                    'ccu_estado' => 'ELIMINADO', 'ccu_razon_codigo' => 'PAGO_'.strtoupper($paymentStatus),
                    'ccu_razon_mensaje' => 'La aplicación se cerró porque el pago no fue aprobado.',
                    'ccu_eliminado_en' => now(), 'ccu_actualizado_en' => now(),
                ]);
            }
        }
    }
}
