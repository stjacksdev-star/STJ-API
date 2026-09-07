<?php

namespace App\Services\Prism;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrismOrderSnapshot
{
    public function load(object $shipment): array
    {
        if ($shipment->integration_environment !== app()->environment()) {
            throw new RuntimeException('El envío no pertenece al APP_ENV actual (históricos sin entorno no se adoptan).');
        }
        $order = DB::table('stj_pedidos')->where('ped_id', $shipment->ped_id)->first();
        $payment = DB::table('stj_pedidos_pago')->where('ppa_id', $shipment->ppa_id)
            ->where('ppa_pedido', $shipment->ped_id)->first();
        if (! $order || ! $payment || strtoupper((string) $payment->ppa_estado) !== 'APROBADA'
            || (string) $payment->ppa_ref !== (string) $shipment->stj_ref
            || (int) $order->ped_id_pais !== (int) $shipment->pais_codigo
            || strtoupper((string) DB::table('stj_paises')->where('pai_id', $order->ped_id_pais)->value('pai_codigo')) !== 'HN') {
            throw new RuntimeException('Pedido/pago/referencia/país inválidos: se requiere Honduras y pago APROBADA vinculado.');
        }
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', (string) $shipment->stj_ref)) {
            throw new RuntimeException('Referencia no compatible con el filtro Prism.');
        }
        $type = strtoupper((string) $payment->ppa_tipo);
        if (! in_array($type, ['EFECTIVO', 'TARJETA'], true) || ($type === 'EFECTIVO' && $order->ped_checkout !== 'TIENDA')) {
            throw new RuntimeException('Modalidad de pago/entrega no soportada.');
        }
        $code = (string) $order->ped_tienda;
        $stores = DB::table('stj_tiendas')->where('tie_pais', $order->ped_id_pais)->where('tie_codigo', $code)->get();
        if ($stores->count() !== 1 || $code !== (string) $shipment->tienda_codigo
            || (string) $stores[0]->tie_codigo !== $code || (string) $stores[0]->prism_sid !== (string) $shipment->tienda_sid
            || ! preg_match('/^[1-9][0-9]*$/D', (string) $stores[0]->prism_sid)
            || ! ctype_digit((string) $stores[0]->prism_store_number) || (int) $stores[0]->prism_store_number < 1) {
            throw new RuntimeException('Tienda Honduras o mapeo Prism no coincide con el pendiente.');
        }
        // Local amounts are reference data only; Prism computes the document total.
        // Retain these keys so existing checkpoints remain compatible when data is unchanged.
        $total = is_numeric($payment->ppa_monto) ? self::cents($payment->ppa_monto) : null;
        $subtotal = is_numeric($payment->ppa_monto_senv) ? self::cents($payment->ppa_monto_senv) : null;
        $dni = preg_replace('/[^0-9]/', '', (string) $order->ped_identificacion);
        $email = strtolower(trim((string) $order->ped_email));
        if ($dni === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || trim((string) $order->ped_nombres) === '') {
            throw new RuntimeException('Cliente sin identificación, nombre o correo válido.');
        }
        $rows = DB::table('stj_pedidos_detalle as d')->leftJoin('stj_productos as p', 'p.pro_id', '=', 'd.car_producto')
            ->where('d.car_ref', $shipment->stj_ref)->where('d.car_accion', 'AGREGADO')->orderBy('d.car_id')
            ->get(['d.*', 'p.pro_codigo']);
        $lines = [];
        foreach ($rows as $row) {
            $style = (string) ($row->car_estilo_final ?: $row->pro_codigo);
            $size = (string) ($row->car_talla_final ?: $row->car_talla);
            $sku = $style.'-'.$size;
            $quantity = (int) $row->car_cantidad;
            $price = self::cents($row->car_precio);
            $discount = is_numeric($row->car_descuento) && $row->car_descuento > 0 ? (float) $row->car_descuento : 0;
            if ((int) $row->car_pais !== (int) $order->ped_id_pais || isset($lines[$sku]) || $quantity < 1
                || $quantity != $row->car_cantidad || $price <= 0 || $discount < 0 || $discount > 100
                || $style === '' || $size === '') {
                throw new RuntimeException('Detalle inválido, de otro país o SKU repetido.');
            }
            $discountCents = (int) round($price * $discount / 100);
            $lines[$sku] = ['sku' => $sku, 'style' => $style, 'size' => $size, 'quantity' => $quantity,
                'discount' => $discountCents / 100, 'price_cents' => $price];
        }
        if ($lines === []) {
            throw new RuntimeException('El pedido no contiene artículos para Prism.');
        }
        if ($type === 'TARJETA' && (! filled($payment->ppa_autorizacion) || ! filled($payment->ppa_tarjeta) || ! filled($payment->ppa_emisor))) {
            throw new RuntimeException('Pago tarjeta sin autorización/tipo/emisor.');
        }

        return ['reference' => (string) $shipment->stj_ref, 'country_id' => (int) $order->ped_id_pais,
            'store_code' => $code, 'store_sid' => (string) $stores[0]->prism_sid,
            // Reference amounts only, never used to set the Prism tender/deposit.
            // Keep the snapshot shape unchanged for existing shipments without freight.
            'store_number' => (int) $stores[0]->prism_store_number, 'type' => $type, 'total_cents' => $subtotal,
            ...($total !== $subtotal ? ['charged_total_cents' => $total] : []),
            'authorization' => (string) ($payment->ppa_autorizacion ?? ''), 'tender_name' => (string) ($payment->ppa_tarjeta ?? ''),
            'card_type' => (string) ($payment->ppa_emisor ?? ''), 'lines' => $lines,
            'customer' => ['info1' => $dni, 'email_address' => $email, 'first_name' => trim((string) $order->ped_nombres),
                'last_name' => trim((string) $order->ped_apellidos), 'address' => trim((string) $order->ped_direccion),
                'phone' => preg_replace('/[^0-9]/', '', (string) $order->ped_telefono)]];
    }

    public static function cents(mixed $amount): int
    {
        if (! is_numeric($amount) || ! is_finite((float) $amount)) {
            throw new RuntimeException('Importe numérico ausente o inválido.');
        }

        return (int) round((float) $amount * 100);
    }
}
