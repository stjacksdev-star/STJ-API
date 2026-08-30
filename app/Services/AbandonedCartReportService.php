<?php

namespace App\Services;

use App\Services\Mail\Smtp2GoMailer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AbandonedCartReportService
{
    public function __construct(private readonly Smtp2GoMailer $mailer) {}

    /** @return array{payment_abandoned:int, cart_abandoned:int, sent:bool, since:string, until:string} */
    public function send(?CarbonImmutable $at = null): array
    {
        $timezone = (string) config('abandoned_carts.timezone', 'America/El_Salvador');
        $until = ($at ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $since = $until->subHours((int) config('abandoned_carts.lookback_hours', 24));
        $inactiveBefore = $until->subMinutes((int) config('abandoned_carts.inactivity_minutes', 60));
        $to = (array) config('abandoned_carts.to', []);

        if ($to === []) {
            throw new RuntimeException('ABANDONED_CART_REPORT_TO no contiene destinatarios validos.');
        }

        $paymentAbandoned = $this->paymentAbandoned($since, $until);
        $cartAbandoned = $this->cartAbandoned($since, $inactiveBefore);

        $this->mailer->sendHtml(
            $to,
            "Carritos Abandonados | St. Jack's Online",
            $this->html($paymentAbandoned, $cartAbandoned, $since, $until),
            (array) config('abandoned_carts.cc', []),
            (array) config('abandoned_carts.bcc', []),
        );

        return [
            'payment_abandoned' => $paymentAbandoned->count(),
            'cart_abandoned' => $cartAbandoned->count(),
            'sent' => true,
            'since' => $since->toDateTimeString(),
            'until' => $until->toDateTimeString(),
        ];
    }

    private function paymentAbandoned(CarbonImmutable $since, CarbonImmutable $until): Collection
    {
        $rows = DB::table('stj_pedidos as orders')
            ->join('stj_paises as country', 'country.pai_id', '=', 'orders.ped_id_pais')
            ->leftJoin('stj_carritos as cart', 'cart.car_pedido_id', '=', 'orders.ped_id')
            ->where('orders.ped_estatus', 'PENDIENTE_PAGO')
            ->whereBetween('orders.ped_fecha', [$since, $until])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stj_pedidos_pago as approved')
                ->whereColumn('approved.ppa_pedido', 'orders.ped_id')->where('approved.ppa_estado', 'APROBADA'))
            ->orderBy('orders.ped_fecha')
            ->get([
                'orders.ped_id', 'orders.ped_fecha', 'orders.ped_checkout', 'orders.ped_nombres',
                'orders.ped_apellidos', 'orders.ped_email', 'orders.ped_telefono', 'orders.ped_origen',
                'orders.ped_plataforma', 'orders.ped_vapp', 'country.pai_nombre', 'cart.car_id',
            ]);

        return $rows->map(function (object $row): object {
            $payments = DB::table('stj_pedidos_pago')->where('ppa_pedido', $row->ped_id)
                ->orderByDesc('ppa_id')->get(['ppa_id', 'ppa_estado']);
            $last = $payments->first();
            $row->paymentAttempts = $payments->where('ppa_estado', '!=', 'PENDIENTE')->count();
            $row->lastPaymentStatus = $last?->ppa_estado ?: 'SIN INTENTO';
            $row->started3ds = $last && DB::table('stj_powertranz_operaciones')->where('pto_pago_id', $last->ppa_id)->exists();
            $row->items = $row->car_id ? $this->items((int) $row->car_id) : collect();

            return $row;
        });
    }

    private function cartAbandoned(CarbonImmutable $since, CarbonImmutable $inactiveBefore): Collection
    {
        return DB::table('stj_carritos as cart')
            ->join('stj_paises as country', 'country.pai_id', '=', 'cart.car_pais_id')
            ->leftJoin('stj_usuarios as customer', 'customer.usu_id', '=', 'cart.car_usu_id')
            ->where('cart.car_estado', 'ACTIVO')->whereNull('cart.car_pedido_id')
            ->whereBetween('cart.car_ultima_actividad_en', [$since, $inactiveBefore])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('stj_carrito_detalles as item')
                ->whereColumn('item.cad_carrito_id', 'cart.car_id'))
            ->orderBy('cart.car_ultima_actividad_en')
            ->get([
                'cart.car_id', 'cart.car_uuid', 'cart.car_tipo', 'cart.car_origen', 'cart.car_ultima_actividad_en',
                'country.pai_nombre', 'customer.usu_nombre', 'customer.usu_apellido',
                'customer.usu_correo', 'customer.usu_telefono',
            ])
            ->map(function (object $row): object {
                $row->items = $this->items((int) $row->car_id);

                return $row;
            });
    }

    private function items(int $cartId): Collection
    {
        return DB::table('stj_carrito_detalles as item')
            ->leftJoin('stj_productos as product', 'product.pro_id', '=', 'item.cad_producto_id')
            ->where('item.cad_carrito_id', $cartId)
            ->get([
                'item.cad_ref', 'item.cad_talla', 'item.cad_cantidad', 'item.cad_precio_final_unitario',
                'item.cad_promocion', 'product.pro_codigo', 'product.pro_nombre',
            ]);
    }

    private function html(Collection $payments, Collection $carts, CarbonImmutable $since, CarbonImmutable $until): string
    {
        $style = '<style>body{font-family:Arial,sans-serif;color:#222}table{width:100%;border-collapse:collapse;margin:12px 0 28px}th,td{border:1px solid #d3d3d3;padding:8px;text-align:left;vertical-align:top}th{background:#007ac9;color:#fff}.meta{color:#555;font-size:12px}.items{margin:6px 0 0;padding-left:18px}</style>';
        $summary = '<h2>Carritos abandonados stjacks.com</h2><p>Periodo: '.$this->e($since->format('d/m/Y H:i')).' al '.$this->e($until->format('d/m/Y H:i')).'</p><p><b>Checkout/pago abandonado:</b> '.$payments->count().' &nbsp; <b>Carrito sin pedido:</b> '.$carts->count().'</p>';

        return $style.$summary.'<h3>Checkout o pago abandonado</h3>'.$this->paymentTable($payments)
            .'<h3>Carrito abandonado antes de crear pedido</h3>'.$this->cartTable($carts)
            .'<p class="meta">Proceso automatico ejecutado: '.$this->e($until->format('d/m/Y H:i:s')).'</p>';
    }

    private function paymentTable(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '<p>No se encontraron registros.</p>';
        }

        $body = $rows->map(fn (object $r): string => '<tr><td>'.$this->e($r->pai_nombre).'</td><td>'.$r->ped_id.'</td><td>'.$this->e($r->ped_checkout).'</td><td>'.$this->e($r->ped_fecha).'</td><td>'.$this->e(trim($r->ped_nombres.' '.$r->ped_apellidos)).'<br>'.$this->e($r->ped_telefono).'<br>'.$this->e($r->ped_email).'</td><td>'.$this->e($r->ped_origen).($r->ped_plataforma ? ' / '.$this->e($r->ped_plataforma) : '').($r->ped_vapp ? '<br>v'.$this->e($r->ped_vapp) : '').'</td><td>'.$this->e($r->lastPaymentStatus).'<br>Intentos: '.$r->paymentAttempts.'<br>3DS: '.($r->started3ds ? 'SI' : 'NO').'</td><td>'.$this->itemList($r->items).'</td></tr>')->implode('');

        return '<table><thead><tr><th>País</th><th>Pedido</th><th>Checkout</th><th>Fecha</th><th>Cliente</th><th>Canal</th><th>Pago</th><th>Productos</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private function cartTable(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '<p>No se encontraron registros.</p>';
        }

        $body = $rows->map(fn (object $r): string => '<tr><td>'.$this->e($r->pai_nombre).'</td><td>'.$r->car_id.'<br><span class="meta">'.$this->e($r->car_uuid).'</span></td><td>'.$this->e($r->car_tipo).'</td><td>'.$this->e($r->car_ultima_actividad_en).'</td><td>'.$this->e(trim(($r->usu_nombre ?? '').' '.($r->usu_apellido ?? ''))).'<br>'.$this->e($r->usu_telefono ?? '').'<br>'.$this->e($r->usu_correo ?? '').'</td><td>'.$this->e($r->car_origen).'</td><td>'.$this->itemList($r->items).'</td></tr>')->implode('');

        return '<table><thead><tr><th>País</th><th>Carrito</th><th>Checkout</th><th>Última actividad</th><th>Cliente</th><th>Canal</th><th>Productos</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private function itemList(Collection $items): string
    {
        if ($items->isEmpty()) {
            return 'Sin productos disponibles';
        }

        return '<ul class="items">'.$items->map(fn (object $item): string => '<li>'.$this->e($item->pro_nombre ?: $item->cad_ref).' | '.$this->e($item->pro_codigo ?: $item->cad_ref).' | Talla '.$this->e($item->cad_talla).' | Cant. '.(int) $item->cad_cantidad.' | $'.$this->e($item->cad_precio_final_unitario).($item->cad_promocion ? ' | '.$this->e($item->cad_promocion) : '').'</li>')->implode('').'</ul>';
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
