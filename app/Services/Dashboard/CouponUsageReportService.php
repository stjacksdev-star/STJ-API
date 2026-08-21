<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CouponUsageReportService
{
    public function report(string $countryCode, string $startDate, string $endDate, ?string $search, int $page = 1, int $perPage = 20): array
    {
        $country = DB::table('stj_paises')
            ->whereRaw('UPPER(pai_codigo) = ?', [strtoupper($countryCode)])
            ->first(['pai_id', 'pai_codigo', 'pai_nombre']);

        if (! $country) {
            return ['country' => null, 'rows' => [], 'summary' => $this->emptySummary(), 'pagination' => $this->pagination(1, $perPage, 0)];
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        $term = trim((string) $search);
        $payments = DB::table('stj_pedidos_pago')
            ->where('ppa_estado', 'APROBADA')
            ->groupBy('ppa_pedido')
            ->selectRaw('ppa_pedido, MAX(ppa_id) as payment_id');

        $base = DB::table('stj_pedido_cupones_aplicados as usage')
            ->join('stj_pedidos as orders', 'orders.ped_id', '=', 'usage.pca_pedido_id')
            ->joinSub($payments, 'approved', fn ($join) => $join->on('approved.ppa_pedido', '=', 'orders.ped_id'))
            ->join('stj_pedidos_pago as payment', 'payment.ppa_id', '=', 'approved.payment_id')
            ->leftJoin('stj_cupones as coupon', 'coupon.cup_id', '=', 'usage.pca_cupon_id')
            ->where('usage.pca_estado', 'CONSUMIDO')
            ->where('orders.ped_id_pais', $country->pai_id)
            ->whereBetween('usage.pca_consumido_en', [$start, $end])
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.$term.'%';
                $query->where(fn ($filter) => $filter
                    ->where('usage.pca_codigo', 'like', $like)
                    ->orWhere('orders.ped_email', 'like', $like));
            });

        $totals = (clone $base)->selectRaw('COUNT(*) as total, COALESCE(SUM(usage.pca_descuento_productos), 0) as product_discount, COALESCE(SUM(usage.pca_descuento_envio), 0) as shipping_discount, COALESCE(SUM(usage.pca_descuento_total), 0) as total_discount')->first();
        $total = (int) ($totals->total ?? 0);
        $pagination = $this->pagination($page, $perPage, $total);

        $rows = $base
            ->orderByDesc('usage.pca_consumido_en')
            ->orderByDesc('usage.pca_id')
            ->forPage($pagination['page'], $pagination['perPage'])
            ->get([
                'usage.pca_id', 'usage.pca_pedido_id', 'usage.pca_codigo', 'usage.pca_nombre', 'usage.pca_tipo',
                'usage.pca_descuento_productos', 'usage.pca_descuento_envio', 'usage.pca_descuento_total', 'usage.pca_consumido_en',
                'coupon.cup_correo', 'coupon.cup_descuento', 'coupon.cup_monto',
                'orders.ped_nombres', 'orders.ped_apellidos', 'orders.ped_email', 'orders.ped_fecha', 'orders.ped_checkout',
                'payment.ppa_ref', 'payment.ppa_monto_sdesc', 'payment.ppa_monto_senv',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->pca_id,
                'usedAt' => (string) $row->pca_consumido_en,
                'code' => (string) $row->pca_codigo,
                'couponName' => (string) ($row->pca_nombre ?? ''),
                'couponType' => (string) $row->pca_tipo,
                'configuredDiscount' => (float) ($row->cup_descuento ?? 0),
                'configuredAmount' => (float) ($row->cup_monto ?? 0),
                'productDiscount' => (float) $row->pca_descuento_productos,
                'shippingDiscount' => (float) $row->pca_descuento_envio,
                'totalDiscount' => (float) $row->pca_descuento_total,
                'orderId' => (int) $row->pca_pedido_id,
                'orderReference' => (string) ($row->ppa_ref ?? ''),
                'orderAmount' => (float) ($row->ppa_monto_sdesc ?? 0),
                'orderFinalAmount' => (float) ($row->ppa_monto_senv ?? 0),
                'currency' => $this->currency((string) $country->pai_codigo),
                'checkout' => (string) ($row->ped_checkout ?? ''),
                'customerName' => trim((string) $row->ped_nombres.' '.(string) $row->ped_apellidos),
                'customerEmail' => (string) ($row->ped_email ?: $row->cup_correo),
            ])->all();

        return [
            'country' => ['id' => (int) $country->pai_id, 'code' => (string) $country->pai_codigo, 'name' => (string) $country->pai_nombre],
            'rows' => $rows,
            'summary' => ['uses' => $total, 'productDiscount' => (float) $totals->product_discount, 'shippingDiscount' => (float) $totals->shipping_discount, 'totalDiscount' => (float) $totals->total_discount],
            'pagination' => $pagination,
        ];
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        $perPage = max(10, min(100, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage];
    }

    private function emptySummary(): array
    {
        return ['uses' => 0, 'productDiscount' => 0.0, 'shippingDiscount' => 0.0, 'totalDiscount' => 0.0];
    }

    private function currency(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'GT' => 'GTQ', 'CR' => 'CRC', 'HN' => 'HNL', 'DO' => 'DOP', default => 'USD',
        };
    }
}
