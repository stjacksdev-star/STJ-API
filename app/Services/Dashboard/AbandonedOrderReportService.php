<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class AbandonedOrderReportService
{
    public function report(array $filters): array
    {
        $countryValue = trim((string) $filters['country']);
        $country = DB::table('stj_paises')->where(function ($query) use ($countryValue) {
            $query->whereRaw('UPPER(pai_codigo) = ?', [strtoupper($countryValue)]);
            if (ctype_digit($countryValue)) $query->orWhere('pai_id', (int) $countryValue);
        })->first(['pai_id', 'pai_codigo', 'pai_nombre']);
        abort_unless($country, 404, 'Pais no encontrado.');

        $latestPayment = DB::table('stj_pedidos_pago')->selectRaw('ppa_pedido, MAX(ppa_id) as payment_id')->groupBy('ppa_pedido');
        $latestFailure = DB::table('stj_checkout_eventos')
            ->selectRaw('coe_pedido_id, MAX(coe_id) as event_id')
            ->whereNotNull('coe_pedido_id')
            ->whereIn('coe_resultado', ['ERROR', 'REJECTED', 'EXPIRED'])
            ->groupBy('coe_pedido_id');

        $base = DB::table('stj_pedidos as orders')
            ->leftJoinSub($latestPayment, 'latest_payment', 'latest_payment.ppa_pedido', '=', 'orders.ped_id')
            ->leftJoin('stj_pedidos_pago as payment', 'payment.ppa_id', '=', 'latest_payment.payment_id')
            ->leftJoinSub($latestFailure, 'latest_failure', 'latest_failure.coe_pedido_id', '=', 'orders.ped_id')
            ->leftJoin('stj_checkout_eventos as event', 'event.coe_id', '=', 'latest_failure.event_id')
            ->where('orders.ped_id_pais', $country->pai_id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stj_pedidos_pago as approved')->whereColumn('approved.ppa_pedido', 'orders.ped_id')->where('approved.ppa_estado', 'APROBADA'))
            ->when($filters['startDate'] ?? null, fn ($query, $date) => $query->where('orders.ped_fecha', '>=', $date.' 00:00:00'))
            ->when($filters['endDate'] ?? null, fn ($query, $date) => $query->where('orders.ped_fecha', '<=', $date.' 23:59:59'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('payment.ppa_estado', $status === 'SIN_PAGO' ? null : $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.trim($search).'%';
                $query->where(fn ($nested) => $nested->where('orders.ped_id', 'like', $term)
                    ->orWhere('payment.ppa_ref', 'like', $term)->orWhere('orders.ped_email', 'like', $term)
                    ->orWhereRaw("CONCAT(COALESCE(orders.ped_nombres, ''), ' ', COALESCE(orders.ped_apellidos, '')) LIKE ?", [$term]));
            });

        $summaryRows = (clone $base)->selectRaw("COALESCE(payment.ppa_estado, 'SIN_PAGO') as status, COUNT(*) as total")->groupByRaw("COALESCE(payment.ppa_estado, 'SIN_PAGO')")->pluck('total', 'status');
        $page = (clone $base)->select([
            'orders.ped_id as orderId', 'orders.ped_fecha as createdAt', 'orders.ped_estatus as orderStatus', 'orders.ped_checkout as checkout',
            'orders.ped_origen as origin', 'orders.ped_nombres as firstName', 'orders.ped_apellidos as lastName', 'orders.ped_email as email',
            'payment.ppa_id as paymentId', 'payment.ppa_ref as reference', 'payment.ppa_tipo as paymentMethod', 'payment.ppa_estado as paymentStatus',
            'payment.ppa_monto as amount', 'payment.ppa_fecha as paymentCreatedAt', 'payment.ppa_fecha_procesado as processedAt',
            'payment.ppa_rsp_codigo as providerCode', 'payment.ppa_rsp_mensaje as providerMessage',
            'event.coe_etapa as failureStage', 'event.coe_evento as failureEvent', 'event.coe_codigo as failureCode', 'event.coe_mensaje as failureMessage',
            'event.coe_proveedor_mensaje as eventProviderMessage', 'event.coe_ocurrido_en as failureAt',
        ])->orderByDesc('orders.ped_fecha')->paginate($filters['perPage'], ['*'], 'page', $filters['page']);

        $rows = collect($page->items())->map(function ($row) {
            $reason = $row->providerMessage ?: $row->eventProviderMessage ?: $row->failureMessage;
            if (! $reason) $reason = $row->paymentStatus ? 'El pago no fue aprobado.' : 'El pedido se creó sin registrar un pago.';
            return [
                'orderId' => (int) $row->orderId, 'createdAt' => $row->createdAt, 'orderStatus' => $row->orderStatus,
                'checkout' => $row->checkout, 'origin' => $row->origin,
                'customer' => trim($row->firstName.' '.$row->lastName), 'email' => $row->email,
                'paymentId' => $row->paymentId ? (int) $row->paymentId : null, 'reference' => $row->reference,
                'paymentMethod' => $row->paymentMethod, 'paymentStatus' => $row->paymentStatus ?: 'SIN_PAGO',
                'amount' => $row->amount !== null ? round((float) $row->amount, 2) : null,
                'failureStage' => $row->failureStage, 'failureEvent' => $row->failureEvent,
                'failureCode' => $row->providerCode ?: $row->failureCode, 'reason' => $reason,
                'failureAt' => $row->failureAt ?: $row->processedAt ?: $row->paymentCreatedAt,
            ];
        });

        return [
            'rows' => $rows,
            'summary' => ['total' => (int) $summaryRows->sum(), 'byStatus' => $summaryRows->map(fn ($value) => (int) $value)],
            'country' => ['id' => (int) $country->pai_id, 'code' => strtoupper($country->pai_codigo), 'name' => $country->pai_nombre],
            'pagination' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ];
    }
}
