<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesKpiService
{
    /**
     * @param array<string, mixed> $filters
     */
    public function orders(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $pending = filter_var($filters['pending'] ?? false, FILTER_VALIDATE_BOOL);

        if ($pending) {
            $storeInfo = $this->resolveStore($countryId, $filters['store'] ?? null);
            $start = $this->nullableDate($filters['startDate'] ?? null);
            $end = $this->nullableDate($filters['endDate'] ?? null);

            if (($start && ! $end) || (! $start && $end)) {
                throw ValidationException::withMessages([
                    'endDate' => 'Debe enviar ambas fechas o ninguna para pedidos pendientes.',
                ]);
            }

            if ($start !== null && $end !== null && $start > $end) {
                throw ValidationException::withMessages([
                    'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
                ]);
            }

            return $this->pendingOrderDetails(
                $countryId,
                $storeInfo,
                $start,
                $end,
            );
        }

        $start = Carbon::parse((string) ($filters['startDate'] ?? now()->toDateString()))->toDateString();
        $end = Carbon::parse((string) ($filters['endDate'] ?? now()->toDateString()))->toDateString();

        if ($start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        return $this->approvedOrderDetails(
            $countryId,
            $start,
            $end,
            $this->stringOrNull($filters['origin'] ?? null),
            $this->stringOrNull($filters['checkout'] ?? null),
        );
    }

    public function kpi(?string $country = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $countries = $this->countries();

        if (! filled($country) || ! filled($startDate) || ! filled($endDate)) {
            return [
                'countries' => $countries,
                'filters' => [
                    'country' => null,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ],
                'summary' => [],
                'summaryTotals' => $this->emptySummaryTotals(),
                'margin' => $this->emptyMoneyPair(),
                'preparedTotals' => $this->emptyMoneyPair(),
                'salesByHour' => [],
                'salesByStore' => [],
                'promotions' => [
                    'rows' => [],
                    'unassigned' => $this->emptyPromotionSale(),
                    'totals' => $this->emptyPromotionSale(),
                ],
                'pendingOrders' => [
                    'rows' => [],
                    'totals' => [
                        'orders' => 0,
                        'items' => 0,
                        'amount' => 0.0,
                    ],
                ],
            ];
        }

        $countryId = $this->resolveCountryId($country);
        $start = Carbon::parse($startDate)->toDateString();
        $end = Carbon::parse($endDate)->toDateString();

        if ($start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        $summary = $this->summary($countryId, $start, $end);

        return [
            'countries' => $countries,
            'filters' => [
                'country' => $countryId,
                'startDate' => $start,
                'endDate' => $end,
            ],
            'summary' => $summary,
            'summaryTotals' => $this->summaryTotals($summary),
            'margin' => $this->margin($countryId, $start, $end),
            'preparedTotals' => $this->preparedTotals($countryId, $start, $end),
            'salesByHour' => $this->salesByHour($countryId, $start, $end),
            'salesByStore' => $this->salesByStore($countryId, $start, $end),
            'promotions' => $this->promotions($countryId, $start, $end),
            'pendingOrders' => $this->pendingOrders($countryId),
        ];
    }

    private function summary(int $countryId, string $start, string $end): array
    {
        $rows = DB::select(
            "SELECT
                ped_checkout,
                ped_origen,
                COUNT(CASE WHEN ped_estatus IN ('RECIBIDO','PREPARADO','EN-RUTA','ENTREGADO') AND ped_estatus_productos = 'COMPLETO' THEN ppa_ref END) AS cantidad_total,
                COUNT(CASE WHEN ped_estatus IN ('RECIBIDO','PREPARADO','EN-RUTA','ENTREGADO') AND ped_estatus_productos = 'INCOMPLETO' THEN ppa_ref END) AS cantidad_parcial,
                COUNT(CASE WHEN ped_estatus IN ('ANULADO-CLIENTE','ANULADO-INVENTARIO','DEVOLUCION') THEN ppa_ref END) AS cantidad_devolucion,
                SUM(CASE WHEN ped_estatus IN ('RECIBIDO','PREPARADO','EN-RUTA','ENTREGADO') AND ped_estatus_productos = 'COMPLETO' THEN ppa_monto_senv END) AS total,
                SUM(CASE WHEN ped_estatus IN ('ANULADO-CLIENTE','ANULADO-INVENTARIO','DEVOLUCION') THEN ppa_monto_senv END) AS devolucion,
                SUM(CASE WHEN ped_estatus IN ('RECIBIDO','PREPARADO','EN-RUTA','ENTREGADO') AND ped_estatus_productos = 'INCOMPLETO' THEN ped_monto_devolucion END) AS devolucion_parcial,
                SUM(CASE WHEN ped_estatus IN ('RECIBIDO','PREPARADO','EN-RUTA','ENTREGADO') AND ped_estatus_productos = 'INCOMPLETO' THEN ppa_monto_senv - ped_monto_devolucion END) AS total_parcial
            FROM stj_pedidos
            INNER JOIN stj_pedidos_pago ON ppa_pedido = ped_id AND ppa_estado = 'APROBADA'
            WHERE DATE(ppa_fecha) >= ? AND DATE(ppa_fecha) <= ? AND ped_id_pais = ?
            GROUP BY ped_checkout, ped_origen
            ORDER BY ped_origen, ped_checkout",
            [$start, $end, $countryId],
        );

        return collect($rows)
            ->map(fn ($row) => [
                'origin' => (string) $row->ped_origen,
                'checkout' => (string) $row->ped_checkout,
                'completeOrders' => (int) $row->cantidad_total,
                'partialOrders' => (int) $row->cantidad_parcial,
                'cancelledOrders' => (int) $row->cantidad_devolucion,
                'completeAmount' => (float) ($row->total ?? 0),
                'partialAmount' => (float) ($row->total_parcial ?? 0),
                'refundAmount' => (float) (($row->devolucion ?? 0) + ($row->devolucion_parcial ?? 0)),
                'totalAmount' => (float) (($row->total ?? 0) + ($row->total_parcial ?? 0)),
            ])
            ->values()
            ->all();
    }

    private function margin(int $countryId, string $start, string $end): array
    {
        $row = DB::table('stj_pedidos_pago')
            ->join('stj_pedidos', 'ppa_pedido', '=', 'ped_id')
            ->whereRaw('DATE(ppa_fecha) >= ?', [$start])
            ->whereRaw('DATE(ppa_fecha) <= ?', [$end])
            ->where('ppa_estado', 'APROBADA')
            ->where('ped_id_pais', $countryId)
            ->selectRaw('SUM(ppa_monto_sdesc) AS subtotal, SUM(ppa_monto_senv) AS total')
            ->first();

        return $this->moneyPair($row);
    }

    private function preparedTotals(int $countryId, string $start, string $end): array
    {
        $row = DB::table('stj_pedidos')
            ->join('stj_pedidos_pago', function ($join) {
                $join->on('ppa_pedido', '=', 'ped_id')
                    ->where('ppa_estado', '=', 'APROBADA');
            })
            ->where('ped_id_pais', $countryId)
            ->whereRaw('DATE(ppa_fecha) >= ?', [$start])
            ->whereRaw('DATE(ppa_fecha) <= ?', [$end])
            ->whereIn('ped_estatus', ['PREPARADO', 'EN-RUTA'])
            ->selectRaw('SUM(ppa_monto_senv) AS total, SUM(ppa_monto_sdesc) AS subtotal')
            ->first();

        return $this->moneyPair($row);
    }

    private function salesByHour(int $countryId, string $start, string $end): array
    {
        return DB::table('stj_pedidos')
            ->leftJoin('stj_pedidos_pago', 'ppa_pedido', '=', 'ped_id')
            ->whereRaw('DATE(ped_fecha) >= ?', [$start])
            ->whereRaw('DATE(ped_fecha) <= ?', [$end])
            ->where('ppa_estado', 'APROBADA')
            ->where('ped_id_pais', $countryId)
            ->groupByRaw('ped_checkout, HOUR(ped_fecha), DATE(ped_fecha)')
            ->orderByRaw('DATE(ped_fecha), HOUR(ped_fecha), ped_checkout')
            ->selectRaw('ped_checkout, DATE(ped_fecha) AS fecha, HOUR(ped_fecha) AS hora, COUNT(*) AS total, SUM(ppa_articulos) AS cantidad, SUM(ppa_monto_senv) AS monto')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->fecha,
                'hour' => (int) $row->hora,
                'checkout' => (string) $row->ped_checkout,
                'orders' => (int) $row->total,
                'items' => (int) ($row->cantidad ?? 0),
                'amount' => (float) ($row->monto ?? 0),
            ])
            ->values()
            ->all();
    }

    private function salesByStore(int $countryId, string $start, string $end): array
    {
        return DB::table('stj_pedidos')
            ->leftJoin('stj_pedidos_pago', 'ppa_pedido', '=', 'ped_id')
            ->leftJoin('stj_tiendas', function ($join) use ($countryId) {
                $join->on('tie_codigo', '=', 'ped_tienda')
                    ->where('tie_pais', '=', $countryId);
            })
            ->whereRaw('DATE(ped_fecha) >= ?', [$start])
            ->whereRaw('DATE(ped_fecha) <= ?', [$end])
            ->where('ppa_estado', 'APROBADA')
            ->where('ped_id_pais', $countryId)
            ->groupBy('ped_checkout', 'tie_nombre')
            ->orderByDesc('monto')
            ->selectRaw("CASE WHEN ped_checkout = 'DOMICILIO' THEN 'Domicilio' ELSE tie_nombre END AS nombreT, COUNT(*) AS total, SUM(ppa_articulos) AS cantidad, SUM(ppa_monto_senv) AS monto")
            ->get()
            ->map(fn ($row) => [
                'store' => (string) ($row->nombreT ?: 'N/D'),
                'orders' => (int) $row->total,
                'items' => (int) ($row->cantidad ?? 0),
                'amount' => (float) ($row->monto ?? 0),
            ])
            ->values()
            ->all();
    }

    private function pendingOrders(int $countryId): array
    {
        $rows = DB::table('stj_pedidos')
            ->join('stj_tiendas', function ($join) use ($countryId) {
                $join->on('tie_codigo', '=', 'ped_tienda')
                    ->where('tie_pais', '=', $countryId);
            })
            ->join('stj_pedidos_pago', function ($join) {
                $join->on('ppa_pedido', '=', 'ped_id')
                    ->where('ppa_estado', '=', 'APROBADA');
            })
            ->where('ped_estatus', 'RECIBIDO')
            ->where('ped_id_pais', $countryId)
            ->groupBy('ped_checkout', 'tie_nombre', 'tie_correo', 'tie_codigo')
            ->selectRaw("CASE WHEN ped_checkout = 'DOMICILIO' THEN 'Domicilio' ELSE tie_nombre END AS nombreT, tie_correo AS correo, COUNT(*) AS total, SUM(ppa_articulos) AS articulos, SUM(ppa_monto_senv) AS venta, tie_codigo, MAX(tie_id) AS tie_id")
            ->get()
            ->map(fn ($row) => [
                'store' => (string) ($row->nombreT ?: 'N/D'),
                'email' => (string) ($row->correo ?? ''),
                'orders' => (int) $row->total,
                'items' => (int) ($row->articulos ?? 0),
                'amount' => (float) ($row->venta ?? 0),
                'storeCode' => (string) $row->tie_codigo,
                'storeId' => (int) ($row->tie_id ?? 0),
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'orders' => array_sum(array_column($rows, 'orders')),
                'items' => array_sum(array_column($rows, 'items')),
                'amount' => array_sum(array_column($rows, 'amount')),
            ],
        ];
    }

    private function approvedOrderDetails(int $countryId, string $start, string $end, ?string $origin, ?string $checkout): array
    {
        $query = $this->orderDetailBaseQuery($countryId)
            ->where('pay.ppa_estado', 'APROBADA')
            ->where('p.ped_id_pais', $countryId)
            ->whereRaw('DATE(pay.ppa_fecha) BETWEEN ? AND ?', [$start, $end])
            ->when($origin !== null, fn ($builder) => $builder->where('p.ped_origen', $origin))
            ->when($checkout !== null, fn ($builder) => $builder->where('p.ped_checkout', $checkout))
            ->orderByDesc('pay.ppa_fecha');

        $rows = $query->get()->map(fn ($row) => $this->normalizeOrderDetail($row))->values()->all();

        return [
            'filters' => [
                'country' => $countryId,
                'startDate' => $start,
                'endDate' => $end,
                'origin' => $origin,
                'checkout' => $checkout,
                'pending' => false,
                'store' => null,
            ],
            'summary' => $this->orderDetailTotals($rows),
            'orders' => $rows,
        ];
    }

    /**
     * @param array{code: ?string, id: ?int, name: ?string}|null $store
     */
    private function pendingOrderDetails(int $countryId, ?array $store, ?string $start, ?string $end): array
    {
        $query = $this->orderDetailBaseQuery($countryId)
            ->where('pay.ppa_estado', 'APROBADA')
            ->where('p.ped_estatus', 'RECIBIDO')
            ->where('p.ped_id_pais', $countryId)
            ->when($store['code'] ?? null, fn ($builder, $code) => $builder->where('p.ped_tienda', $code))
            ->when($start !== null && $end !== null, fn ($builder) => $builder->whereRaw('DATE(pay.ppa_fecha) BETWEEN ? AND ?', [$start, $end]))
            ->orderByDesc('pay.ppa_fecha');

        $rows = $query->get()->map(fn ($row) => $this->normalizeOrderDetail($row))->values()->all();

        return [
            'filters' => [
                'country' => $countryId,
                'startDate' => $start,
                'endDate' => $end,
                'origin' => null,
                'checkout' => null,
                'pending' => true,
                'store' => $store['code'] ?? null,
                'storeId' => $store['id'] ?? null,
                'storeName' => $store['name'] ?? null,
            ],
            'summary' => $this->orderDetailTotals($rows),
            'orders' => $rows,
        ];
    }

    private function orderDetailBaseQuery(int $countryId)
    {
        return DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', 'p.ped_id', '=', 'pay.ppa_pedido')
            ->leftJoin('stj_pedidos_direccion as pd', 'pd.pdi_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_direcciones as d', 'pd.pdi_direccion', '=', 'd.dir_id')
            ->leftJoin('stj_pedidos_tienda as pt', 'pt.pti_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_tiendas as order_store', function ($join) use ($countryId) {
                $join->on('order_store.tie_codigo', '=', 'pt.pti_tienda')
                    ->where('order_store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_tiendas as pending_store', function ($join) use ($countryId) {
                $join->on('pending_store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('pending_store.tie_pais', '=', $countryId);
            })
            ->selectRaw("
                p.ped_id_pais,
                COALESCE(order_store.tie_nombre, pending_store.tie_nombre) AS tie_nombre,
                COALESCE(order_store.tie_codigo, pending_store.tie_codigo, p.ped_tienda) AS tie_codigo,
                COALESCE(order_store.tie_id, pending_store.tie_id) AS tie_id,
                p.ped_checkout,
                p.ped_origen,
                pay.ppa_ref AS ref,
                pay.ppa_fecha,
                p.ped_nombres,
                p.ped_apellidos,
                p.ped_identificacion,
                p.ped_email,
                pay.ppa_tipo,
                pay.ppa_emisor,
                pay.ppa_tarjeta,
                pay.ppa_monto,
                pay.ppa_monto_senv,
                pay.ppa_articulos,
                pay.ppa_cambio,
                CONCAT_WS(', ', d.dir_direccion, d.dir_municipio_txt, d.dir_departamento_txt) AS direccion
            ");
    }

    private function normalizeOrderDetail(object $row): array
    {
        $paymentType = (string) ($row->ppa_tipo ?? '');

        return [
            'countryId' => (int) $row->ped_id_pais,
            'storeCode' => (string) ($row->tie_codigo ?? ''),
            'storeId' => $row->tie_id !== null ? (int) $row->tie_id : null,
            'storeName' => (string) ($row->tie_nombre ?? ''),
            'origin' => (string) ($row->ped_origen ?? ''),
            'checkout' => (string) ($row->ped_checkout ?? ''),
            'ref' => (string) ($row->ref ?? ''),
            'paidAt' => (string) ($row->ppa_fecha ?? ''),
            'customer' => trim((string) ($row->ped_nombres ?? '').' '.(string) ($row->ped_apellidos ?? '')),
            'identification' => (string) ($row->ped_identificacion ?? ''),
            'email' => (string) ($row->ped_email ?? ''),
            'paymentType' => $paymentType,
            'issuer' => $paymentType === 'EFECTIVO' ? 'EFECTIVO' : (string) ($row->ppa_emisor ?? ''),
            'cardOrChange' => $paymentType === 'EFECTIVO'
                ? 'Cambio: '.(string) ($row->ppa_cambio ?? '')
                : (string) ($row->ppa_tarjeta ?? ''),
            'amount' => (float) ($row->ppa_monto_senv ?? $row->ppa_monto ?? 0),
            'items' => (int) ($row->ppa_articulos ?? 0),
            'destination' => (string) ($row->ped_checkout ?? '') === 'DOMICILIO'
                ? (string) ($row->direccion ?? '')
                : 'Tienda: '.(string) ($row->tie_nombre ?? ''),
        ];
    }

    private function orderDetailTotals(array $rows): array
    {
        return [
            'orders' => count($rows),
            'items' => array_sum(array_column($rows, 'items')),
            'amount' => array_sum(array_column($rows, 'amount')),
        ];
    }

    private function promotions(int $countryId, string $start, string $end): array
    {
        $sales = $this->promotionSales($countryId, $start, $end);
        $unassigned = $sales[0] ?? $this->emptyPromotionSale();

        $rows = collect($this->activePromotions($countryId, $start, $end))
            ->map(function ($promotion) use ($sales) {
                $sale = $sales[(int) $promotion->prm_id] ?? $this->emptyPromotionSale();

                return [
                    'id' => (int) $promotion->prm_id,
                    'name' => trim((string) $promotion->prm_nombre),
                    'commercialName' => trim((string) $promotion->prm_nombre_comercial),
                    'status' => (string) $promotion->prm_estado,
                    'type' => (string) $promotion->prm_tipo_promocion,
                    'startAt' => (string) $promotion->pho_inicio,
                    'endAt' => (string) $promotion->pho_fin,
                    'units' => $sale['units'],
                    'grossAmount' => $sale['grossAmount'],
                    'netAmount' => $sale['netAmount'],
                    'discountAmount' => $sale['discountAmount'],
                ];
            })
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'unassigned' => $unassigned,
            'totals' => [
                'units' => array_sum(array_column($rows, 'units')) + $unassigned['units'],
                'grossAmount' => array_sum(array_column($rows, 'grossAmount')) + $unassigned['grossAmount'],
                'netAmount' => array_sum(array_column($rows, 'netAmount')) + $unassigned['netAmount'],
                'discountAmount' => array_sum(array_column($rows, 'discountAmount')) + $unassigned['discountAmount'],
            ],
        ];
    }

    private function activePromotions(int $countryId, string $start, string $end): array
    {
        $query = DB::table('stj_promociones')
            ->join('stj_promociones_horario', 'pho_promocion', '=', 'prm_id')
            ->where('prm_pais', $countryId)
            ->where('pho_tipo', 'NORMAL');

        if ($start === $end) {
            $query
                ->whereRaw('DATE(pho_inicio) <= ?', [$start])
                ->whereRaw('DATE(pho_fin) >= ?', [$end]);
        } else {
            $query
                ->whereRaw('DATE(pho_inicio) >= ?', [$start])
                ->where(function ($builder) use ($end) {
                    $builder
                        ->whereRaw('DATE(pho_fin) <= ?', [$end])
                        ->orWhereRaw('DATE(pho_fin) >= ?', [$end]);
                })
                ->orderByDesc('pho_inicio');
        }

        return $query
            ->select([
                'prm_id',
                'prm_nombre',
                'prm_nombre_comercial',
                'prm_estado',
                'prm_tipo_promocion',
                'pho_inicio',
                'pho_fin',
            ])
            ->get()
            ->all();
    }

    private function promotionSales(int $countryId, string $start, string $end): array
    {
        $rows = DB::select(
            "SELECT
                prm_id,
                car_promocion,
                SUM(car_cantidad) AS unidades,
                SUM(car_cantidad * ppais.ppa_precio) AS totalSD,
                SUM(car_cantidad * (ppais.ppa_precio * (1 - (car_descuento / 100)))) AS totalCD
            FROM stj_pedidos_detalle
            INNER JOIN stj_pedidos_pago AS pago ON car_ref = pago.ppa_ref AND pago.ppa_estado = 'APROBADA'
            INNER JOIN stj_pedidos ON ped_id = pago.ppa_pedido AND ped_estatus IN ('PREPARADO','EN-RUTA')
            INNER JOIN stj_productos ON pro_id = car_producto
            INNER JOIN stj_producto_pais AS ppais ON pro_id = ppais.ppa_producto AND ppais.ppa_pais = ?
            LEFT JOIN stj_promociones ON car_promocion_id = prm_id
            WHERE ped_id_pais = ?
                AND car_accion = 'AGREGADO'
                AND DATE(car_fecha) >= ?
                AND DATE(car_fecha) <= ?
            GROUP BY prm_id, car_promocion",
            [$countryId, $countryId, $start, $end],
        );

        return collect($rows)
            ->mapWithKeys(function ($row) {
                $gross = (float) ($row->totalSD ?? 0);
                $net = (float) ($row->totalCD ?? 0);

                return [
                    (int) ($row->prm_id ?? 0) => [
                        'units' => (int) ($row->unidades ?? 0),
                        'grossAmount' => $gross,
                        'netAmount' => $net,
                        'discountAmount' => $gross - $net,
                    ],
                ];
            })
            ->all();
    }

    private function summaryTotals(array $summary): array
    {
        $totals = $this->emptySummaryTotals();

        foreach ($summary as $row) {
            $totals['completeOrders'] += $row['completeOrders'];
            $totals['partialOrders'] += $row['partialOrders'];
            $totals['cancelledOrders'] += $row['cancelledOrders'];
            $totals['completeAmount'] += $row['completeAmount'];
            $totals['partialAmount'] += $row['partialAmount'];
            $totals['refundAmount'] += $row['refundAmount'];
            $totals['totalAmount'] += $row['totalAmount'];
        }

        return $totals;
    }

    private function emptySummaryTotals(): array
    {
        return [
            'completeOrders' => 0,
            'partialOrders' => 0,
            'cancelledOrders' => 0,
            'completeAmount' => 0.0,
            'partialAmount' => 0.0,
            'refundAmount' => 0.0,
            'totalAmount' => 0.0,
        ];
    }

    private function moneyPair(?object $row): array
    {
        $subtotal = (float) ($row->subtotal ?? 0);
        $total = (float) ($row->total ?? 0);

        return [
            'subtotal' => $subtotal,
            'total' => $total,
            'discount' => $subtotal - $total,
            'discountRate' => $subtotal > 0 ? round((($subtotal - $total) / $subtotal) * 100, 2) : 0.0,
        ];
    }

    private function emptyMoneyPair(): array
    {
        return [
            'subtotal' => 0.0,
            'total' => 0.0,
            'discount' => 0.0,
            'discountRate' => 0.0,
        ];
    }

    private function emptyPromotionSale(): array
    {
        return [
            'units' => 0,
            'grossAmount' => 0.0,
            'netAmount' => 0.0,
            'discountAmount' => 0.0,
        ];
    }

    private function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->orderBy('pai_nombre')
            ->get()
            ->map(fn ($country) => [
                'id' => (int) $country->pai_id,
                'code' => strtoupper((string) $country->pai_codigo),
                'name' => trim((string) $country->pai_nombre),
            ])
            ->values()
            ->all();
    }

    private function resolveCountryId(string $country): int
    {
        $country = trim($country);
        $query = DB::table('stj_paises')->select(['pai_id']);

        $resolved = is_numeric($country)
            ? $query->where('pai_id', (int) $country)->first()
            : $query->where('pai_codigo', strtoupper($country))->first();

        if (! $resolved) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return (int) $resolved->pai_id;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value !== null ? Carbon::parse($value)->toDateString() : null;
    }

    /**
     * @return array{code: ?string, id: ?int, name: ?string}|null
     */
    private function resolveStore(int $countryId, mixed $store): ?array
    {
        $store = $this->stringOrNull($store);

        if ($store === null) {
            return null;
        }

        $query = DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId);

        $resolved = (clone $query)->where('tie_codigo', $store)->first();

        if (! $resolved && is_numeric($store)) {
            $resolved = (clone $query)->where('tie_id', (int) $store)->first();
        }

        if (! $resolved) {
            throw ValidationException::withMessages([
                'store' => 'La tienda seleccionada no existe para el pais indicado.',
            ]);
        }

        return [
            'id' => (int) $resolved->tie_id,
            'code' => (string) $resolved->tie_codigo,
            'name' => trim((string) $resolved->tie_nombre),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
