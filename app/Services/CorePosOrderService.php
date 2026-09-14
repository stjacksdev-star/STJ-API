<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CorePosOrderService
{
    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        $header = DB::table('stj_pedidos_pago as payment')
            ->join('stj_pedidos as orders', 'orders.ped_id', '=', 'payment.ppa_pedido')
            ->where('payment.ppa_ref', $reference)
            ->where('payment.ppa_estado', 'APROBADA')
            ->where('orders.ped_id_pais', 1)
            ->whereIn('orders.ped_estatus', ['RECIBIDO', 'PREPARADO', 'EMPACADO-ENTREGA'])
            ->orderByDesc('payment.ppa_id')
            ->select([
                'orders.ped_id',
                'orders.ped_estatus',
                'orders.ped_nombres',
                'orders.ped_apellidos',
                'orders.ped_email',
                'orders.ped_tipo_identificacion',
                'orders.ped_identificacion',
                'orders.ped_pais',
                'orders.ped_direccion',
                'orders.ped_telefono_pais',
                'orders.ped_telefono',
                'payment.ppa_tipo',
                'payment.ppa_ref',
                'payment.ppa_fecha',
                'payment.ppa_monto_sdesc',
                'payment.ppa_monto_senv',
                'payment.ppa_monto',
                'payment.ppa_emisor',
                'payment.ppa_autorizacion',
            ])
            ->first();

        if (! $header) {
            return null;
        }

        $items = DB::table('stj_pedidos_detalle as detail')
            ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->where('detail.car_ref', $header->ppa_ref)
            ->where('detail.car_cantidad', '>', 0)
            ->orderBy('detail.car_id')
            ->get([
                'product.pro_codigo',
                'detail.car_talla',
                'detail.car_cantidad',
                'detail.car_precio',
                'detail.car_descuento',
                'detail.car_promocion',
            ])
            ->map(fn (object $item): array => [
                'sku' => (string) $item->pro_codigo.'-'.(string) $item->car_talla,
                'codigo' => $item->pro_codigo,
                'talla' => $item->car_talla,
                'cantidad' => $item->car_cantidad,
                'precio' => $item->car_precio,
                'porcentaje_descuento' => $item->car_descuento,
                'promocion' => $item->car_promocion,
            ])
            ->all();

        return [
            'pedido' => [
                'id' => $header->ped_id,
                'stj' => $header->ppa_ref,
                'fecha' => $header->ppa_fecha,
                'estado' => $header->ped_estatus,
                'pais' => 'SV',
            ],
            'cliente' => [
                'nombres' => $header->ped_nombres,
                'apellidos' => $header->ped_apellidos,
                'correo' => $header->ped_email,
                'tipo_documento' => $header->ped_tipo_identificacion,
                'documento' => $header->ped_identificacion,
                'pais' => $header->ped_pais,
                'direccion' => $header->ped_direccion,
                'telefono' => $this->formattedPhone($header->ped_telefono_pais, $header->ped_telefono),
            ],
            'pago' => [
                'tipo' => $header->ppa_tipo,
                'emisor' => $header->ppa_emisor,
                'autorizacion' => $header->ppa_autorizacion,
            ],
            'totales' => [
                'monto_sin_descuento' => $header->ppa_monto_sdesc,
                'total_sin_envio' => $header->ppa_monto_senv,
                'total' => $header->ppa_monto,
            ],
            'items' => $items,
        ];
    }

    private function formattedPhone(mixed $countryCode, mixed $phone): string
    {
        $phone = (string) $phone;
        $formatted = strlen($phone) > 4 ? substr($phone, 0, 4).'-'.substr($phone, 4) : $phone;

        return trim((string) $countryCode.' '.$formatted);
    }
}
