<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontOrderTrackingController extends BaseController
{
    public function show(Request $request, string $country)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'min:8', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ], [
            'reference.regex' => 'Ingresa una referencia STJ válida.',
        ]);

        $countryRow = DB::table('stj_paises')
            ->whereRaw('UPPER(pai_codigo) = ?', [strtoupper($country)])
            ->first(['pai_id', 'pai_codigo']);

        if (! $countryRow) {
            throw ValidationException::withMessages(['country' => 'País no soportado.']);
        }

        $reference = strtoupper(trim($data['reference']));
        $order = DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payments', function ($join) use ($reference) {
                $join->on('payments.ppa_pedido', '=', 'orders.ped_id')
                    ->where('payments.ppa_ref', '=', $reference)
                    ->where('payments.ppa_estado', '=', 'APROBADA');
            })
            ->leftJoin('stj_pedidos_direccion as delivery', 'delivery.pdi_pedido', '=', 'orders.ped_id')
            ->where('orders.ped_id_pais', $countryRow->pai_id)
            ->orderByDesc('payments.ppa_id')
            ->first([
                'orders.ped_estatus', 'orders.ped_checkout',
                'payments.ppa_ref', 'payments.ppa_fecha', 'payments.ppa_fecha_procesado',
                'payments.ppa_fecha_entregado', 'payments.ppa_articulos', 'payments.ppa_monto',
                'delivery.pdi_fecha_ruta', 'delivery.pdi_id_shipping',
            ]);

        if (! $order) {
            return $this->error('No encontramos un pedido con esa referencia en este país.', 404);
        }

        $status = strtoupper(trim((string) $order->ped_estatus));
        $delivery = strtoupper(trim((string) $order->ped_checkout)) === 'DOMICILIO';
        $steps = [
            ['key' => 'received', 'label' => 'Recibido', 'date' => $order->ppa_fecha, 'reached' => true],
            ['key' => 'prepared', 'label' => 'Facturado', 'date' => $order->ppa_fecha_procesado, 'reached' => in_array($status, ['PREPARADO', 'EN-RUTA', 'ENTREGADO'], true)],
        ];
        if ($delivery) {
            $steps[] = ['key' => 'route', 'label' => 'En ruta', 'date' => $order->pdi_fecha_ruta, 'reached' => in_array($status, ['EN-RUTA', 'ENTREGADO'], true)];
        }
        $steps[] = ['key' => 'delivered', 'label' => 'Entregado', 'date' => $order->ppa_fecha_entregado, 'reached' => $status === 'ENTREGADO'];

        return $this->success([
            'reference' => $order->ppa_ref,
            'checkout' => $order->ped_checkout,
            'items' => (int) ($order->ppa_articulos ?? 0),
            'amount' => $order->ppa_monto !== null ? (float) $order->ppa_monto : null,
            'status' => $status,
            'shippingId' => $order->pdi_id_shipping ?: null,
            'steps' => $steps,
        ], 'Estado del pedido obtenido.');
    }
}
