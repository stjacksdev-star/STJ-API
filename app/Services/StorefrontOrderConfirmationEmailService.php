<?php

namespace App\Services;

use App\Services\Mail\Smtp2GoMailer;
use App\Services\Mail\StorefrontMailTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StorefrontOrderConfirmationEmailService
{
    public function __construct(
        private readonly Smtp2GoMailer $mailer,
        private readonly StorefrontMailTemplate $template,
    ) {}

    public function send(int $orderId, int $paymentId): void
    {
        $claimed = DB::table('stj_pedidos')
            ->where('ped_id', $orderId)
            ->where('ped_correo_enviado', 'NO')
            ->update(['ped_correo_enviado' => 'SI']);

        if ($claimed !== 1) {
            return;
        }

        try {
            $order = $this->order($orderId, $paymentId);
            if (! $order || strtoupper((string) $order->ppa_estado) !== 'APROBADA') {
                throw new RuntimeException('El pedido no tiene un pago aprobado para confirmar.');
            }

            $items = DB::table('stj_pedidos_detalle as detail')
                ->leftJoin('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
                ->where('detail.car_ref', $order->ppa_ref)
                ->where('detail.car_accion', 'AGREGADO')
                ->get(['detail.car_estilo_final', 'detail.car_talla_final', 'detail.car_talla', 'detail.car_cantidad', 'detail.car_precio', 'detail.car_descuento_final', 'product.pro_codigo', 'product.pro_nombre']);

            $country = strtolower((string) $order->pai_codigo);
            $customerEmail = trim((string) $order->ped_email);
            if ($customerEmail === '') {
                throw new RuntimeException('El pedido no tiene correo de cliente.');
            }

            $this->mailer->sendHtml(
                $customerEmail,
                "Gracias por tu compra St. Jack's ".trim((string) $order->pai_nombre),
                $this->template->render($this->customerContent($order, $items), $country),
            );

            $storeEmail = trim((string) $order->tie_correo);
            if ($storeEmail !== '') {
                $this->mailer->sendHtml(
                    $storeEmail,
                    'Pedido #'.$order->ppa_ref.' recibido',
                    $this->template->render($this->storeContent($order), $country),
                );
            }

        } catch (\Throwable $exception) {
            DB::table('stj_pedidos')->where('ped_id', $orderId)->where('ped_correo_enviado', 'SI')->update(['ped_correo_enviado' => 'NO']);
            throw $exception;
        }
    }

    private function order(int $orderId, int $paymentId): ?object
    {
        return DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payment', 'payment.ppa_pedido', '=', 'orders.ped_id')
            ->join('stj_paises as country', 'country.pai_id', '=', 'orders.ped_id_pais')
            ->leftJoin('stj_tiendas as store', function ($join) {
                $join->on('store.tie_codigo', '=', 'orders.ped_tienda')->on('store.tie_pais', '=', 'orders.ped_id_pais');
            })
            ->leftJoin('stj_pedidos_direccion as shipping', 'shipping.pdi_pedido', '=', 'orders.ped_id')
            ->leftJoin('stj_direcciones as address', 'address.dir_id', '=', 'shipping.pdi_direccion')
            ->where('orders.ped_id', $orderId)
            ->where('payment.ppa_id', $paymentId)
            ->selectRaw('orders.*, payment.*, country.pai_codigo, country.pai_nombre, store.tie_nombre, store.tie_correo, address.dir_direccion, address.dir_referencia, address.dir_departamento_txt, address.dir_municipio_txt, shipping.pdi_costo_envio_txt')
            ->first();
    }

    private function customerContent(object $order, $items): string
    {
        $name = e(trim($order->ped_nombres.' '.$order->ped_apellidos));
        $destination = strtoupper((string) $order->ped_checkout) === 'DOMICILIO'
            ? e(implode(', ', array_filter([$order->dir_direccion, $order->dir_municipio_txt, $order->dir_departamento_txt])))
            : 'Retiro en tienda: '.e((string) $order->tie_nombre);
        $currency = $this->currency((string) $order->pai_codigo);
        $rows = $items->map(function ($item) use ($currency) {
            $price = (float) $item->car_precio * (1 - ((float) $item->car_descuento_final / 100));
            return '<tr><td style="padding:8px;border-bottom:1px solid #e5e7eb">'.e((string) ($item->pro_codigo ?: $item->car_estilo_final)).'</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">'.e((string) ($item->car_talla_final ?: $item->car_talla)).'</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">'.e((string) $item->pro_nombre).'</td><td style="padding:8px;text-align:center;border-bottom:1px solid #e5e7eb">'.(int) $item->car_cantidad.'</td><td style="padding:8px;text-align:right;border-bottom:1px solid #e5e7eb">'.$currency.number_format($price, 2).'</td></tr>';
        })->implode('');
        $payment = strtoupper((string) $order->ppa_tipo) === 'EFECTIVO'
            ? 'Efectivo al retirar'
            : 'Tarjeta'.($order->ppa_autorizacion ? ' · autorización '.e((string) $order->ppa_autorizacion) : '');

        return '<h1 style="margin:0 0 18px;font-size:26px">¡Gracias por tu compra!</h1>'
            .'<p>Hola <strong>'.$name.'</strong>, tu pago fue procesado con éxito y recibimos tu pedido.</p>'
            .'<table role="presentation" width="100%" style="margin:22px 0;border-collapse:collapse;font-size:14px">'
            .$this->infoRow('Comprobante', e((string) $order->ppa_ref))
            .$this->infoRow('Destino', $destination)
            .$this->infoRow('Método de pago', $payment)
            .$this->infoRow('Teléfono', e(trim($order->ped_telefono_pais.' '.$order->ped_telefono)))
            .$this->infoRow('Total', $currency.number_format((float) $order->ppa_monto, 2)).'</table>'
            .'<table role="presentation" width="100%" style="border-collapse:collapse;font-size:13px"><tr><th align="left">SKU</th><th align="left">Talla</th><th align="left">Descripción</th><th>Cant.</th><th align="right">Precio</th></tr>'.$rows.'</table>';
    }

    private function storeContent(object $order): string
    {
        $checkout = strtoupper((string) $order->ped_checkout) === 'DOMICILIO' ? 'PEDIDO A DOMICILIO' : 'PEDIDO PARA RETIRO EN TIENDA';
        return '<h1 style="margin:0 0 18px;font-size:26px">Pedido recibido</h1><p><strong>'.$checkout.'</strong></p><table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px">'
            .$this->infoRow('Comprobante', e((string) $order->ppa_ref))
            .$this->infoRow('Nombre', e(trim($order->ped_nombres.' '.$order->ped_apellidos)))
            .$this->infoRow('Teléfono', e(trim($order->ped_telefono_pais.' '.$order->ped_telefono)))
            .$this->infoRow('Hora del pedido', e((string) $order->ppa_fecha)).'</table><p style="margin-top:22px">Ingresa al administrador para gestionar el pedido.</p>';
    }

    private function infoRow(string $label, string $value): string
    {
        return '<tr><td style="padding:7px 12px 7px 0;font-weight:bold;vertical-align:top">'.e($label).'</td><td style="padding:7px 0">'.$value.'</td></tr>';
    }

    private function currency(string $country): string
    {
        return match (strtoupper($country)) { 'GT' => 'Q', 'HN' => 'L', default => 'USD $' };
    }
}
