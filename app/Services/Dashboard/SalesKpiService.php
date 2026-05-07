<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesKpiService
{
    private const PROCESSED_ORDER_STATUSES = [
        'PREPARADO',
        'EN-RUTA',
        'ENTREGADO',
        'ANULADO-ERROR',
        'ANULADO-PRUEBA',
        'ANULADO-CLIENTE',
        'ANULADO-INVENTARIO',
        'DEVOLUCION',
        'ANULADO-EFECTIVO',
    ];

    /**
     * @param array<string, mixed> $filters
     */
    public function orders(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $pending = filter_var($filters['pending'] ?? false, FILTER_VALIDATE_BOOL);
        $statuses = $this->statuses($filters['statuses'] ?? null);

        if ($statuses !== []) {
            $storeInfo = $this->resolveStore($countryId, $filters['store'] ?? null);
            $start = $this->nullableDate($filters['startDate'] ?? null);
            $end = $this->nullableDate($filters['endDate'] ?? null);

            if (($start && ! $end) || (! $start && $end)) {
                throw ValidationException::withMessages([
                    'endDate' => 'Debe enviar ambas fechas o ninguna para pedidos procesados.',
                ]);
            }

            if ($start !== null && $end !== null && $start > $end) {
                throw ValidationException::withMessages([
                    'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
                ]);
            }

            return $this->orderDetailsByStatuses(
                $countryId,
                $storeInfo,
                $start,
                $end,
                $statuses,
            );
        }

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
                'stores' => [],
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
            'stores' => $this->stores($countryId),
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

    public function regionalSalesChart(?string $startDate = null, ?string $endDate = null): array
    {
        $end = Carbon::parse($endDate ?: now()->toDateString())->toDateString();
        $start = Carbon::parse($startDate ?: Carbon::parse($end)->subDays(7)->toDateString())->toDateString();

        if ($start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        $previousStart = Carbon::parse($start)->subYear()->toDateString();
        $previousEnd = Carbon::parse($end)->subYear()->toDateString();
        $currentYear = Carbon::parse($end)->year;
        $previousYear = Carbon::parse($previousEnd)->year;
        $dates = $this->dateRange($start, $end);
        $hnlUsdRate = $this->latestHnlUsdRate();

        $currentRows = $this->regionalSalesRows($start, $end, $hnlUsdRate['rate']);
        $previousRows = $this->regionalSalesRows($previousStart, $previousEnd, $hnlUsdRate['rate']);

        $countrySeries = [
            [
                'key' => 'sv',
                'countryId' => 1,
                'country' => 'El Salvador',
                'current' => 'ElSalvador',
                'previous' => 'ElSalvador',
            ],
            [
                'key' => 'gt',
                'countryId' => 2,
                'country' => 'Guatemala',
                'current' => 'Guatemala',
                'previous' => 'Guatemala',
            ],
            [
                'key' => 'cr',
                'countryId' => 3,
                'country' => 'Costa Rica',
                'current' => 'CostaRica',
                'previous' => 'CostaRica',
            ],
            [
                'key' => 'hn',
                'countryId' => 7,
                'country' => 'Honduras',
                'current' => 'Honduras',
                'previous' => 'Honduras',
            ],
        ];

        $series = [];

        foreach ($countrySeries as $country) {
            $series[] = [
                'key' => $country['key'].'_current',
                'countryId' => $country['countryId'],
                'country' => $country['country'],
                'period' => 'current',
                'year' => $currentYear,
                'label' => $country['country'].' ('.$currentYear.')',
                'data' => $this->regionalSalesValues($dates, $currentRows, $country['current']),
            ];

            $series[] = [
                'key' => $country['key'].'_previous',
                'countryId' => $country['countryId'],
                'country' => $country['country'],
                'period' => 'previous',
                'year' => $previousYear,
                'label' => $country['country'].' ('.$previousYear.')',
                'data' => $this->regionalSalesValues($dates, $previousRows, $country['previous'], $previousStart),
            ];
        }

        $series[] = [
            'key' => 'total_current',
            'countryId' => 0,
            'country' => 'Consolidado',
            'period' => 'current',
            'year' => $currentYear,
            'label' => 'Consolidado ('.$currentYear.')',
            'data' => $this->consolidatedValues($dates, $currentRows),
        ];

        $series[] = [
            'key' => 'total_previous',
            'countryId' => 0,
            'country' => 'Consolidado',
            'period' => 'previous',
            'year' => $previousYear,
            'label' => 'Consolidado ('.$previousYear.')',
            'data' => $this->consolidatedValues($dates, $previousRows, $previousStart),
        ];

        return [
            'filters' => [
                'startDate' => $start,
                'endDate' => $end,
                'previousStartDate' => $previousStart,
                'previousEndDate' => $previousEnd,
            ],
            'categories' => $dates,
            'series' => $series,
            'notes' => [
                'currency' => 'USD',
                'exchangeRates' => [
                    'GT' => 0.13049,
                    'CR' => 0.0017594,
                    'HN' => $hnlUsdRate,
                ],
            ],
        ];
    }

    public function conversionChart(?string $startDate = null, ?string $endDate = null, ?string $country = null): array
    {
        $end = Carbon::parse($endDate ?: now()->toDateString())->toDateString();
        $start = Carbon::parse($startDate ?: Carbon::parse($end)->subDays(7)->toDateString())->toDateString();

        if ($start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        $countryInfo = $this->conversionCountry($country);
        $previousStart = Carbon::parse($start)->subYear()->toDateString();
        $previousEnd = Carbon::parse($end)->subYear()->toDateString();
        $dates = $this->dateRange($start, $end);
        $previousDates = $this->dateRange($previousStart, $previousEnd);

        $currentVisits = $this->visitsByDate($start, $end, $countryInfo['visitCountry']);
        $previousVisits = $this->visitsByDate($previousStart, $previousEnd, $countryInfo['visitCountry']);
        $currentOrders = $this->approvedOrdersByDate($start, $end, $countryInfo['countryId']);
        $previousOrders = $this->approvedOrdersByDate($previousStart, $previousEnd, $countryInfo['countryId']);

        $rows = collect($dates)
            ->map(function (string $date, int $index) use ($previousDates, $currentVisits, $previousVisits, $currentOrders, $previousOrders) {
                $previousDate = $previousDates[$index] ?? null;
                $visits = (int) ($currentVisits[$date] ?? 0);
                $orders = (int) ($currentOrders[$date] ?? 0);
                $previousVisitCount = $previousDate ? (int) ($previousVisits[$previousDate] ?? 0) : 0;
                $previousOrderCount = $previousDate ? (int) ($previousOrders[$previousDate] ?? 0) : 0;

                return [
                    'date' => $date,
                    'previousDate' => $previousDate,
                    'visits' => $visits,
                    'orders' => $orders,
                    'rate' => $visits > 0 ? round(($orders / $visits) * 100, 2) : 0.0,
                    'previousVisits' => $previousVisitCount,
                    'previousOrders' => $previousOrderCount,
                    'previousRate' => $previousVisitCount > 0 ? round(($previousOrderCount / $previousVisitCount) * 100, 2) : 0.0,
                ];
            })
            ->values()
            ->all();

        return [
            'filters' => [
                'startDate' => $start,
                'endDate' => $end,
                'previousStartDate' => $previousStart,
                'previousEndDate' => $previousEnd,
                'country' => $countryInfo['key'],
                'countryLabel' => $countryInfo['label'],
            ],
            'categories' => $dates,
            'series' => [
                [
                    'key' => 'conversion_current',
                    'period' => 'current',
                    'label' => 'Conversion '.Carbon::parse($end)->year,
                    'data' => array_column($rows, 'rate'),
                ],
                [
                    'key' => 'conversion_previous',
                    'period' => 'previous',
                    'label' => 'Conversion '.Carbon::parse($previousEnd)->year,
                    'data' => array_column($rows, 'previousRate'),
                ],
            ],
            'rows' => $rows,
            'totals' => [
                'visits' => array_sum(array_column($rows, 'visits')),
                'orders' => array_sum(array_column($rows, 'orders')),
                'rate' => $this->conversionRate(
                    array_sum(array_column($rows, 'orders')),
                    array_sum(array_column($rows, 'visits')),
                ),
                'previousVisits' => array_sum(array_column($rows, 'previousVisits')),
                'previousOrders' => array_sum(array_column($rows, 'previousOrders')),
                'previousRate' => $this->conversionRate(
                    array_sum(array_column($rows, 'previousOrders')),
                    array_sum(array_column($rows, 'previousVisits')),
                ),
            ],
        ];
    }

    public function visitsChart(?string $startDate = null, ?string $endDate = null, ?string $country = null, ?string $previousStartDate = null, ?string $previousEndDate = null): array
    {
        $end = Carbon::parse($endDate ?: now()->toDateString())->toDateString();
        $start = Carbon::parse($startDate ?: Carbon::parse($end)->subDays(7)->toDateString())->toDateString();

        if ($start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        $countryInfo = $this->conversionCountry($country);

        if ($countryInfo['key'] === 'general') {
            $dates = $this->dateRange($start, $end);
            $platformRows = $this->visitsByPlatform($start, $end);

            return [
                'mode' => 'general',
                'filters' => [
                    'startDate' => $start,
                    'endDate' => $end,
                    'country' => 'general',
                    'countryLabel' => 'General',
                ],
                'categories' => $dates,
                'series' => collect([
                    ['key' => 'visits_web', 'label' => 'WEB', 'column' => 'web'],
                    ['key' => 'visits_android', 'label' => 'Android', 'column' => 'android'],
                    ['key' => 'visits_ios', 'label' => 'iOS', 'column' => 'ios'],
                ])->map(fn ($serie) => [
                    'key' => $serie['key'],
                    'label' => $serie['label'],
                    'data' => collect($dates)->map(fn ($date) => (int) ($platformRows[$date][$serie['column']] ?? 0))->all(),
                ])->all(),
                'rows' => $this->visitTotalsByCountry($start, $end),
            ];
        }

        $previousEnd = Carbon::parse($previousEndDate ?: Carbon::parse($end)->subYear()->toDateString())->toDateString();
        $previousStart = Carbon::parse($previousStartDate ?: Carbon::parse($start)->subYear()->toDateString())->toDateString();

        if ($previousStart > $previousEnd) {
            throw ValidationException::withMessages([
                'previousEndDate' => 'La fecha anterior fin debe ser mayor o igual a la fecha anterior inicio.',
            ]);
        }

        $dates = $this->dateRange($start, $end);
        $previousDates = $this->dateRange($previousStart, $previousEnd);
        $currentVisits = $this->visitsByDate($start, $end, $countryInfo['visitCountry']);
        $previousVisits = $this->visitsByDate($previousStart, $previousEnd, $countryInfo['visitCountry']);

        $rows = collect($dates)
            ->map(function (string $date, int $index) use ($previousDates, $currentVisits, $previousVisits) {
                $previousDate = $previousDates[$index] ?? null;

                return [
                    'index' => $index + 1,
                    'date' => $date,
                    'visits' => (int) ($currentVisits[$date] ?? 0),
                    'previousDate' => $previousDate,
                    'previousVisits' => $previousDate ? (int) ($previousVisits[$previousDate] ?? 0) : 0,
                ];
            })
            ->values()
            ->all();

        return [
            'mode' => 'country',
            'filters' => [
                'startDate' => $start,
                'endDate' => $end,
                'previousStartDate' => $previousStart,
                'previousEndDate' => $previousEnd,
                'country' => $countryInfo['key'],
                'countryLabel' => $countryInfo['label'],
            ],
            'categories' => $dates,
            'series' => [
                [
                    'key' => 'visits_current',
                    'period' => 'current',
                    'label' => 'Visitas actuales',
                    'data' => array_column($rows, 'visits'),
                ],
                [
                    'key' => 'visits_previous',
                    'period' => 'previous',
                    'label' => 'Visitas anteriores',
                    'data' => array_column($rows, 'previousVisits'),
                ],
            ],
            'rows' => $rows,
            'totals' => [
                'visits' => array_sum(array_column($rows, 'visits')),
                'previousVisits' => array_sum(array_column($rows, 'previousVisits')),
            ],
        ];
    }

    public function satisfaction(): array
    {
        $now = now();

        $byCountry = $this->otifRows([
            'select' => 'countries.pai_nombre AS country, p.ped_origen AS origin',
            'groupBy' => ['countries.pai_nombre', 'p.ped_origen'],
            'orderBy' => ['countries.pai_nombre', 'otif DESC'],
        ])->map(fn (object $row) => [
            'country' => (string) $row->country,
            'origin' => (string) $row->origin,
            'otif' => round((float) $row->otif, 2),
        ])->values()->all();

        $byPaymentType = $this->otifRows([
            'select' => 'countries.pai_nombre AS country, p.ped_origen AS origin, pay.ppa_tipo AS paymentType',
            'groupBy' => ['pay.ppa_tipo', 'countries.pai_nombre', 'p.ped_origen'],
            'orderBy' => ['countries.pai_nombre', 'otif DESC'],
        ])->map(fn (object $row) => [
            'country' => (string) $row->country,
            'origin' => (string) $row->origin,
            'paymentType' => (string) $row->paymentType,
            'otif' => round((float) $row->otif, 2),
        ])->values()->all();

        $byCheckout = $this->otifRows([
            'select' => 'countries.pai_nombre AS country, p.ped_origen AS origin, p.ped_checkout AS checkout',
            'groupBy' => ['p.ped_checkout', 'countries.pai_nombre', 'p.ped_origen'],
            'orderBy' => ['countries.pai_nombre', 'otif DESC'],
        ])->map(fn (object $row) => [
            'country' => (string) $row->country,
            'origin' => (string) $row->origin,
            'checkout' => (string) $row->checkout,
            'otif' => round((float) $row->otif, 2),
        ])->values()->all();

        $byStore = $this->otifRows([
            'joinStores' => true,
            'select' => 'countries.pai_nombre AS country, stores.tie_nombre AS store',
            'groupBy' => ['countries.pai_nombre', 'stores.tie_nombre'],
            'orderBy' => ['countries.pai_nombre', 'otif DESC'],
        ])->map(fn (object $row) => [
            'country' => (string) $row->country,
            'store' => (string) $row->store,
            'otif' => round((float) $row->otif, 2),
        ])->values()->all();

        return [
            'filters' => [
                'month' => $now->month,
                'year' => $now->year,
                'monthLabel' => $now->translatedFormat('F Y'),
                'deliveryDays' => 7,
            ],
            'legend' => [
                ['label' => '0% - 25%', 'class' => 'range25', 'color' => '#ef4444'],
                ['label' => '25% - 50%', 'class' => 'range50', 'color' => '#f59e0b'],
                ['label' => '50% - 75%', 'class' => 'range75', 'color' => '#facc15'],
                ['label' => '75% - 100%', 'class' => 'range100', 'color' => '#16a34a'],
            ],
            'byCountry' => $byCountry,
            'byPaymentType' => $byPaymentType,
            'byCheckout' => $byCheckout,
            'byStore' => [
                'countries' => collect($byStore)->pluck('country')->unique()->values()->all(),
                'rows' => $byStore,
            ],
        ];
    }

    public function categorySales(?string $startDate = null, ?string $endDate = null): array
    {
        $end = Carbon::parse($endDate ?: now()->toDateString())->toDateString();
        $start = Carbon::parse($startDate ?: Carbon::parse($end)->subDays(3)->toDateString())->toDateString();

        if ($start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        $rows = collect(DB::select(
            "SELECT *
            FROM (
                SELECT
                    p.ped_id_pais AS countryId,
                    countries.pai_nombre AS country,
                    DATE(pay.ppa_fecha) AS date,
                    categories.cat_nombre AS category,
                    CONVERT(SUM(detail.car_cantidad * ((detail.car_precio * (CASE WHEN p.ped_id_pais = 2 THEN 0.13049 WHEN p.ped_id_pais = 3 THEN 0.0017594 ELSE 1 END)) * (1 - (detail.car_descuento / 100)))) / 1000, DECIMAL(10, 2)) AS sale
                FROM stj_pedidos AS p
                INNER JOIN stj_pedidos_pago AS pay ON pay.ppa_pedido = p.ped_id AND pay.ppa_estado = 'APROBADA'
                INNER JOIN stj_paises AS countries ON countries.pai_id = p.ped_id_pais
                INNER JOIN stj_pedidos_detalle AS detail ON detail.car_ref = pay.ppa_ref
                INNER JOIN stj_productos AS products ON detail.car_producto = products.pro_id
                INNER JOIN stj_categorias AS categories ON categories.cat_id = products.pro_categoria
                INNER JOIN stj_sub_categorias AS subcategories ON subcategories.sca_id = products.pro_sub_categoria
                WHERE detail.car_accion = 'AGREGADO'
                    AND DATE(pay.ppa_fecha) BETWEEN ? AND ?
                GROUP BY p.ped_id_pais, countries.pai_nombre, DATE(pay.ppa_fecha), categories.cat_nombre
                UNION
                SELECT
                    0 AS countryId,
                    'REGIONAL' AS country,
                    DATE(pay.ppa_fecha) AS date,
                    categories.cat_nombre AS category,
                    CONVERT(SUM(detail.car_cantidad * ((detail.car_precio * (CASE WHEN p.ped_id_pais = 2 THEN 0.13049 WHEN p.ped_id_pais = 3 THEN 0.0017594 ELSE 1 END)) * (1 - (detail.car_descuento / 100)))) / 1000, DECIMAL(10, 2)) AS sale
                FROM stj_pedidos AS p
                INNER JOIN stj_pedidos_pago AS pay ON pay.ppa_pedido = p.ped_id AND pay.ppa_estado = 'APROBADA'
                INNER JOIN stj_paises AS countries ON countries.pai_id = p.ped_id_pais
                INNER JOIN stj_pedidos_detalle AS detail ON detail.car_ref = pay.ppa_ref
                INNER JOIN stj_productos AS products ON detail.car_producto = products.pro_id
                INNER JOIN stj_categorias AS categories ON categories.cat_id = products.pro_categoria
                INNER JOIN stj_sub_categorias AS subcategories ON subcategories.sca_id = products.pro_sub_categoria
                WHERE detail.car_accion = 'AGREGADO'
                    AND DATE(pay.ppa_fecha) BETWEEN ? AND ?
                GROUP BY DATE(pay.ppa_fecha), categories.cat_nombre
            ) AS sales
            ORDER BY sale DESC",
            [$start, $end, $start, $end],
        ))
            ->map(fn (object $row) => [
                'countryId' => (int) $row->countryId,
                'country' => (string) $row->country,
                'date' => (string) $row->date,
                'category' => (string) $row->category,
                'sale' => (float) $row->sale,
            ])
            ->values()
            ->all();

        return [
            'filters' => [
                'startDate' => $start,
                'endDate' => $end,
                'currency' => 'USD',
                'unit' => 'thousands',
            ],
            'countries' => [
                ['id' => 0, 'label' => 'Regional', 'country' => 'REGIONAL'],
                ['id' => 1, 'label' => 'El Salvador', 'country' => 'El Salvador'],
                ['id' => 2, 'label' => 'Guatemala', 'country' => 'Guatemala'],
                ['id' => 3, 'label' => 'Costa Rica', 'country' => 'Costa Rica'],
                ['id' => 7, 'label' => 'Honduras', 'country' => 'Honduras'],
            ],
            'dates' => $this->dateRange($start, $end),
            'categories' => collect($rows)->pluck('category')->unique()->values()->all(),
            'rows' => $rows,
        ];
    }

    public function segments(): array
    {
        $hnlUsdRate = $this->latestHnlUsdRate();

        $countries = [
            1 => ['label' => 'El Salvador', 'rate' => 1.0, 'splitOrigin' => true],
            2 => ['label' => 'Guatemala', 'rate' => 0.13049, 'splitOrigin' => false],
            3 => ['label' => 'Costa Rica', 'rate' => 0.0017594, 'splitOrigin' => false],
            7 => ['label' => 'Honduras', 'rate' => $hnlUsdRate['rate'], 'splitOrigin' => false],
        ];

        $rows = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->whereMonth('pay.ppa_fecha', now()->month)
            ->whereYear('pay.ppa_fecha', now()->year)
            ->whereIn('p.ped_id_pais', array_keys($countries))
            ->groupBy('p.ped_id_pais', 'p.ped_origen', 'pay.ppa_tipo', 'p.ped_checkout')
            ->selectRaw('
                p.ped_id_pais AS countryId,
                p.ped_origen AS origin,
                pay.ppa_tipo AS paymentType,
                p.ped_checkout AS checkout,
                SUM(pay.ppa_monto_senv) AS amount,
                SUM(pay.ppa_articulos) AS items,
                COUNT(*) AS orders,
                AVG(pay.ppa_monto_senv / NULLIF(pay.ppa_articulos, 0)) AS averageTicket
            ')
            ->get();

        $segments = [];

        foreach ($rows as $row) {
            $country = $countries[(int) $row->countryId] ?? null;

            if (! $country) {
                continue;
            }

            $rate = (float) $country['rate'];
            $label = (bool) $country['splitOrigin']
                ? $country['label'].' '.ucfirst(strtolower((string) $row->origin))
                : $country['label'];
            $key = $this->segmentKey((int) $row->countryId, (string) $row->origin, (bool) $country['splitOrigin']);

            $segments[] = [
                'key' => $key,
                'label' => $label,
                'countryId' => (int) $row->countryId,
                'country' => $country['label'],
                'origin' => (string) $row->origin,
                'paymentType' => (string) $row->paymentType,
                'checkout' => (string) $row->checkout,
                'orders' => (int) $row->orders,
                'items' => (int) $row->items,
                'amount' => round((float) $row->amount * $rate, 2),
                'averageTicket' => round((float) $row->averageTicket * $rate, 2),
            ];
        }

        $matrix = $this->segmentMatrix($segments);
        $ticketMatrix = $this->segmentTicketMatrix($segments);

        return [
            'filters' => [
                'month' => now()->month,
                'year' => now()->year,
                'monthLabel' => now()->translatedFormat('F Y'),
                'currency' => 'USD',
                'exchangeRates' => [
                    'GT' => 0.13049,
                    'CR' => 0.0017594,
                    'HN' => $hnlUsdRate,
                ],
            ],
            'segments' => [
                ['key' => 'sv_web', 'label' => 'El Salvador Web'],
                ['key' => 'sv_app', 'label' => 'El Salvador App'],
                ['key' => 'gt', 'label' => 'Guatemala'],
                ['key' => 'cr', 'label' => 'Costa Rica'],
                ['key' => 'hn', 'label' => 'Honduras'],
            ],
            'sales' => [
                'rows' => $matrix,
                'totals' => $this->segmentTotals($matrix),
            ],
            'averageTicket' => [
                'rows' => $ticketMatrix,
            ],
            'rawRows' => $segments,
        ];
    }

    public function paymentForms(): array
    {
        $hnlUsdRate = $this->latestHnlUsdRate();
        $countries = [
            1 => ['label' => 'El Salvador', 'rate' => 1.0, 'splitOrigin' => true],
            2 => ['label' => 'Guatemala', 'rate' => 0.13049, 'splitOrigin' => false],
            3 => ['label' => 'Costa Rica', 'rate' => 0.0017594, 'splitOrigin' => false],
            7 => ['label' => 'Honduras', 'rate' => $hnlUsdRate['rate'], 'splitOrigin' => false],
        ];

        $rows = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->whereMonth('pay.ppa_fecha', now()->month)
            ->whereYear('pay.ppa_fecha', now()->year)
            ->whereIn('p.ped_id_pais', array_keys($countries))
            ->groupBy('p.ped_id_pais', 'p.ped_origen', 'p.ped_checkout', 'pay.ppa_tipo', 'pay.ppa_emisor')
            ->selectRaw('
                p.ped_id_pais AS countryId,
                p.ped_origen AS origin,
                p.ped_checkout AS checkout,
                pay.ppa_tipo AS paymentType,
                pay.ppa_emisor AS issuer,
                SUM(pay.ppa_monto_senv) AS amount
            ')
            ->get()
            ->map(function (object $row) use ($countries) {
                $country = $countries[(int) $row->countryId];
                $issuer = strtoupper((string) ($row->paymentType === 'EFECTIVO' ? 'EFECTIVO' : $row->issuer));

                return [
                    'segmentKey' => $this->segmentKey((int) $row->countryId, (string) $row->origin, (bool) $country['splitOrigin']),
                    'segment' => (bool) $country['splitOrigin']
                        ? $country['label'].' '.ucfirst(strtolower((string) $row->origin))
                        : $country['label'],
                    'countryId' => (int) $row->countryId,
                    'country' => $country['label'],
                    'origin' => (string) $row->origin,
                    'checkout' => (string) $row->checkout,
                    'paymentType' => (string) $row->paymentType,
                    'issuer' => $issuer,
                    'amount' => round((float) $row->amount * (float) $country['rate'], 2),
                ];
            })
            ->values()
            ->all();

        return [
            'filters' => [
                'month' => now()->month,
                'year' => now()->year,
                'monthLabel' => now()->translatedFormat('F Y'),
                'currency' => 'USD',
                'exchangeRates' => [
                    'GT' => 0.13049,
                    'CR' => 0.0017594,
                    'HN' => $hnlUsdRate,
                ],
            ],
            'segments' => [
                ['key' => 'sv_web', 'label' => 'El Salvador Web'],
                ['key' => 'sv_app', 'label' => 'El Salvador App'],
                ['key' => 'gt', 'label' => 'Guatemala'],
                ['key' => 'cr', 'label' => 'Costa Rica'],
                ['key' => 'hn', 'label' => 'Honduras'],
            ],
            'issuers' => ['VISA', 'MASTERCARD', 'AMEX', 'EFECTIVO'],
            'store' => $this->paymentFormMatrix($rows, 'TIENDA'),
            'delivery' => $this->paymentFormMatrix($rows, 'DOMICILIO'),
            'rawRows' => $rows,
        ];
    }

    public function geographicSales(): array
    {
        $rows = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->whereMonth('pay.ppa_fecha', now()->month)
            ->whereYear('pay.ppa_fecha', now()->year)
            ->where('p.ped_pais', 'El Salvador')
            ->groupBy('p.ped_estado')
            ->orderByDesc(DB::raw('SUM(pay.ppa_monto_senv)'))
            ->selectRaw('p.ped_estado AS department, SUM(pay.ppa_monto_senv) AS total')
            ->get()
            ->map(function (object $row) {
                $department = trim((string) $row->department);

                return [
                    'id' => $this->salvadorDepartmentId($department),
                    'department' => $department,
                    'total' => round((float) $row->total, 2),
                ];
            })
            ->values()
            ->all();

        return [
            'filters' => [
                'country' => 'El Salvador',
                'countryId' => 1,
                'month' => now()->month,
                'year' => now()->year,
                'monthLabel' => now()->translatedFormat('F Y'),
                'currency' => 'USD',
            ],
            'summary' => [
                'departments' => count($rows),
                'total' => round((float) array_sum(array_column($rows, 'total')), 2),
            ],
            'rows' => $rows,
        ];
    }

    public function appInstallations(?int $year = null): array
    {
        $selectedYear = $year ?: now()->year;

        $rows = DB::table('stj_tokens')
            ->whereYear('tok_fecha', $selectedYear)
            ->groupByRaw('MONTH(tok_fecha)')
            ->orderByRaw('MONTH(tok_fecha) ASC')
            ->selectRaw("
                MONTH(tok_fecha) AS month,
                SUM(CASE WHEN tok_tipo = 'Android' THEN 1 ELSE 0 END) AS android,
                SUM(CASE WHEN tok_tipo = 'Ios' THEN 1 ELSE 0 END) AS ios
            ")
            ->get()
            ->map(fn (object $row) => [
                'month' => (int) $row->month,
                'monthLabel' => Carbon::create($selectedYear, (int) $row->month, 1)->translatedFormat('M'),
                'android' => (int) $row->android,
                'ios' => (int) $row->ios,
            ])
            ->values()
            ->all();

        $totals = [
            'android' => (int) array_sum(array_column($rows, 'android')),
            'ios' => (int) array_sum(array_column($rows, 'ios')),
        ];

        $years = DB::table('stj_tokens')
            ->selectRaw('DISTINCT YEAR(tok_fecha) AS year')
            ->whereNotNull('tok_fecha')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->values()
            ->all();

        return [
            'filters' => [
                'year' => $selectedYear,
            ],
            'years' => $years,
            'platforms' => [
                ['key' => 'android', 'label' => 'Android', 'color' => '#16a34a'],
                ['key' => 'ios', 'label' => 'iOS', 'color' => '#db2777'],
            ],
            'summary' => [
                ...$totals,
                'total' => array_sum($totals),
            ],
            'rows' => $rows,
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

    private function regionalSalesRows(string $start, string $end, float $hnlUsdRate): array
    {
        $rows = DB::select(
            "SELECT
                DATE(ppa_fecha) AS date,
                IFNULL(SUM(CASE WHEN ped_id_pais = 1 THEN ppa_monto_senv END), 0) AS ElSalvador,
                IFNULL(SUM(CASE WHEN ped_id_pais = 2 THEN ppa_monto_senv END), 0) * 0.13049 AS Guatemala,
                IFNULL(SUM(CASE WHEN ped_id_pais = 3 THEN ppa_monto_senv END), 0) * 0.0017594 AS CostaRica,
                IFNULL(SUM(CASE WHEN ped_id_pais = 7 THEN ppa_monto_senv END), 0) * ? AS Honduras
            FROM stj_pedidos
            INNER JOIN stj_pedidos_pago ON ppa_pedido = ped_id AND ppa_estado = 'APROBADA'
            WHERE DATE(ppa_fecha) BETWEEN ? AND ?
            GROUP BY DATE(ppa_fecha)
            ORDER BY DATE(ppa_fecha)",
            [$hnlUsdRate, $start, $end],
        );

        return collect($rows)
            ->mapWithKeys(fn ($row) => [
                (string) $row->date => [
                    'ElSalvador' => (float) ($row->ElSalvador ?? 0),
                    'Guatemala' => (float) ($row->Guatemala ?? 0),
                    'CostaRica' => (float) ($row->CostaRica ?? 0),
                    'Honduras' => (float) ($row->Honduras ?? 0),
                ],
            ])
            ->all();
    }

    private function latestHnlUsdRate(): array
    {
        $row = DB::table('tasa_hnl_usd')
            ->select(['id', 'fecha', 'tasa', 'fuente'])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first();

        return [
            'id' => $row ? (int) $row->id : null,
            'date' => $row ? (string) $row->fecha : null,
            'rate' => $row ? (float) $row->tasa : 0.0,
            'source' => $row ? (string) ($row->fuente ?? '') : null,
        ];
    }

    private function visitsByDate(string $start, string $end, ?string $country): array
    {
        return DB::table('stj_visitas')
            ->whereRaw('DATE(vis_fecha) BETWEEN ? AND ?', [$start, $end])
            ->when($country !== null, fn ($builder) => $builder->where('vis_pais', $country))
            ->groupBy('vis_fecha')
            ->orderBy('vis_fecha')
            ->selectRaw('vis_fecha AS date, COUNT(*) AS visits')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->date => (int) $row->visits])
            ->all();
    }

    private function visitsByPlatform(string $start, string $end): array
    {
        return DB::table('stj_visitas')
            ->whereRaw('DATE(vis_fecha) BETWEEN ? AND ?', [$start, $end])
            ->groupBy('vis_fecha')
            ->orderBy('vis_fecha')
            ->selectRaw("
                vis_fecha AS date,
                IFNULL(SUM(CASE WHEN vis_plataforma = 'WEB' THEN 1 ELSE 0 END), 0) AS web,
                IFNULL(SUM(CASE WHEN vis_plataforma = 'APP-ANDROID' THEN 1 ELSE 0 END), 0) AS android,
                IFNULL(SUM(CASE WHEN vis_plataforma = 'APP-IOS' THEN 1 ELSE 0 END), 0) AS ios
            ")
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->date => [
                    'web' => (int) $row->web,
                    'android' => (int) $row->android,
                    'ios' => (int) $row->ios,
                ],
            ])
            ->all();
    }

    private function visitTotalsByCountry(string $start, string $end): array
    {
        return DB::table('stj_visitas')
            ->whereRaw('DATE(vis_fecha) BETWEEN ? AND ?', [$start, $end])
            ->groupBy('vis_pais')
            ->orderByDesc('visits')
            ->selectRaw("COALESCE(NULLIF(vis_pais, ''), 'N/D') AS country, COUNT(*) AS visits")
            ->get()
            ->map(fn ($row) => [
                'country' => (string) $row->country,
                'visits' => (int) $row->visits,
            ])
            ->values()
            ->all();
    }

    private function approvedOrdersByDate(string $start, string $end, ?int $countryId): array
    {
        return DB::table('stj_pedidos')
            ->join('stj_pedidos_pago', 'ppa_pedido', '=', 'ped_id')
            ->where('ppa_estado', 'APROBADA')
            ->where('ped_estatus', '!=', 'ANULADO-PRUEBA')
            ->whereRaw('DATE(ppa_fecha) BETWEEN ? AND ?', [$start, $end])
            ->when($countryId !== null, fn ($builder) => $builder->where('ped_id_pais', $countryId))
            ->groupByRaw('DATE(ppa_fecha)')
            ->orderByRaw('DATE(ppa_fecha)')
            ->selectRaw('DATE(ppa_fecha) AS date, COUNT(ppa_ref) AS orders')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->date => (int) $row->orders])
            ->all();
    }

    private function conversionCountry(?string $country): array
    {
        return match (strtolower(trim((string) $country))) {
            '1', 'sv', 'elsalvador' => [
                'key' => 'sv',
                'label' => 'El Salvador',
                'visitCountry' => 'ElSalvador',
                'countryId' => 1,
            ],
            '2', 'gt', 'guatemala' => [
                'key' => 'gt',
                'label' => 'Guatemala',
                'visitCountry' => 'Guatemala',
                'countryId' => 2,
            ],
            '3', 'cr', 'costarica' => [
                'key' => 'cr',
                'label' => 'Costa Rica',
                'visitCountry' => 'CostaRica',
                'countryId' => 3,
            ],
            '7', 'hn', 'honduras' => [
                'key' => 'hn',
                'label' => 'Honduras',
                'visitCountry' => 'Honduras',
                'countryId' => 7,
            ],
            default => [
                'key' => 'general',
                'label' => 'General',
                'visitCountry' => null,
                'countryId' => null,
            ],
        };
    }

    private function conversionRate(int|float $orders, int|float $visits): float
    {
        return $visits > 0 ? round(($orders / $visits) * 100, 2) : 0.0;
    }

    private function regionalSalesValues(array $dates, array $rows, string $column, ?string $sourceStart = null): array
    {
        $sourceDate = $sourceStart ? Carbon::parse($sourceStart) : null;

        return collect($dates)
            ->map(function (string $date) use ($rows, $column, &$sourceDate) {
                $lookupDate = $sourceDate ? $sourceDate->toDateString() : $date;
                $value = (float) ($rows[$lookupDate][$column] ?? 0);

                if ($sourceDate) {
                    $sourceDate = $sourceDate->copy()->addDay();
                }

                return round($value, 2);
            })
            ->all();
    }

    private function consolidatedValues(array $dates, array $rows, ?string $sourceStart = null): array
    {
        $sourceDate = $sourceStart ? Carbon::parse($sourceStart) : null;

        return collect($dates)
            ->map(function (string $date) use ($rows, &$sourceDate) {
                $lookupDate = $sourceDate ? $sourceDate->toDateString() : $date;
                $row = $rows[$lookupDate] ?? [];

                if ($sourceDate) {
                    $sourceDate = $sourceDate->copy()->addDay();
                }

                return round(
                    (float) ($row['ElSalvador'] ?? 0)
                    + (float) ($row['Guatemala'] ?? 0)
                    + (float) ($row['CostaRica'] ?? 0)
                    + (float) ($row['Honduras'] ?? 0),
                    2,
                );
            })
            ->all();
    }

    private function dateRange(string $start, string $end): array
    {
        $dates = [];
        $cursor = Carbon::parse($start);
        $last = Carbon::parse($end);

        while ($cursor <= $last) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
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

    /**
     * @param array{code: ?string, id: ?int, name: ?string}|null $store
     * @param array<int, string> $statuses
     */
    private function orderDetailsByStatuses(int $countryId, ?array $store, ?string $start, ?string $end, array $statuses): array
    {
        $query = $this->orderDetailBaseQuery($countryId)
            ->where('pay.ppa_estado', 'APROBADA')
            ->whereIn('p.ped_estatus', $statuses)
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
                'pending' => false,
                'statuses' => $statuses,
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
                p.ped_estatus,
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
            'status' => (string) ($row->ped_estatus ?? ''),
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

    /**
     * @param array<string, mixed> $definition
     */
    private function otifRows(array $definition)
    {
        $query = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', 'pay.ppa_pedido', '=', 'p.ped_id')
            ->join('stj_paises as countries', 'countries.pai_id', '=', 'p.ped_id_pais')
            ->where('pay.ppa_estado', 'APROBADA')
            ->whereMonth('pay.ppa_fecha', now()->month)
            ->whereYear('pay.ppa_fecha', now()->year);

        if ($definition['joinStores'] ?? false) {
            $query->join('stj_tiendas as stores', function ($join) {
                $join->on('stores.tie_codigo', '=', 'p.ped_tienda')
                    ->on('stores.tie_pais', '=', 'p.ped_id_pais');
            });
        }

        foreach ($definition['groupBy'] as $group) {
            $query->groupByRaw($group);
        }

        foreach ($definition['orderBy'] as $order) {
            $query->orderByRaw($order);
        }

        return $query
            ->selectRaw($definition['select'].",
                COALESCE(
                    (
                        IFNULL(SUM(CASE WHEN DATE(pay.ppa_fecha_entregado) <= DATE(DATE_ADD(pay.ppa_fecha, INTERVAL 7 DAY)) THEN pay.ppa_articulos_final END), 0)
                        / NULLIF(IFNULL(SUM(pay.ppa_articulos), 0), 0)
                    ) * 100,
                    0
                ) AS otif
            ")
            ->get();
    }

    private function segmentKey(int $countryId, string $origin, bool $splitOrigin): string
    {
        if ($countryId === 1 && $splitOrigin) {
            return strtoupper($origin) === 'APP' ? 'sv_app' : 'sv_web';
        }

        return match ($countryId) {
            2 => 'gt',
            3 => 'cr',
            7 => 'hn',
            default => 'country_'.$countryId,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    private function segmentMatrix(array $segments): array
    {
        return collect($segments)
            ->groupBy(fn (array $row) => $row['key'].'|'.$row['paymentType'])
            ->map(function ($rows) {
                $first = $rows->first();
                $store = (float) $rows
                    ->where('checkout', 'TIENDA')
                    ->sum('amount');
                $delivery = (float) $rows
                    ->where('checkout', 'DOMICILIO')
                    ->sum('amount');

                return [
                    'key' => $first['key'],
                    'label' => $first['label'],
                    'paymentType' => $first['paymentType'],
                    'store' => round($store, 2),
                    'delivery' => round($delivery, 2),
                    'total' => round($store + $delivery, 2),
                ];
            })
            ->sortBy(fn (array $row) => $this->segmentSort($row['key']).$row['paymentType'])
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    private function segmentTicketMatrix(array $segments): array
    {
        return collect($segments)
            ->groupBy(fn (array $row) => $row['key'].'|'.$row['paymentType'])
            ->map(function ($rows) {
                $first = $rows->first();
                $store = $rows->firstWhere('checkout', 'TIENDA');
                $delivery = $rows->firstWhere('checkout', 'DOMICILIO');

                return [
                    'key' => $first['key'],
                    'label' => $first['label'],
                    'paymentType' => $first['paymentType'],
                    'store' => round((float) ($store['averageTicket'] ?? 0), 2),
                    'delivery' => round((float) ($delivery['averageTicket'] ?? 0), 2),
                ];
            })
            ->sortBy(fn (array $row) => $this->segmentSort($row['key']).$row['paymentType'])
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function segmentTotals(array $rows): array
    {
        $byPayment = collect($rows)
            ->groupBy('paymentType')
            ->map(fn ($paymentRows, string $paymentType) => [
                'paymentType' => $paymentType,
                'store' => round((float) $paymentRows->sum('store'), 2),
                'delivery' => round((float) $paymentRows->sum('delivery'), 2),
                'total' => round((float) $paymentRows->sum('total'), 2),
            ])
            ->values()
            ->all();

        return [
            'byPaymentType' => $byPayment,
            'store' => round((float) array_sum(array_column($rows, 'store')), 2),
            'delivery' => round((float) array_sum(array_column($rows, 'delivery')), 2),
            'total' => round((float) array_sum(array_column($rows, 'total')), 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function paymentFormMatrix(array $rows, string $checkout): array
    {
        $segments = ['sv_web', 'sv_app', 'gt', 'cr', 'hn'];

        return collect(['VISA', 'MASTERCARD', 'AMEX', 'EFECTIVO'])
            ->map(function (string $issuer) use ($rows, $checkout, $segments) {
                $values = [];

                foreach ($segments as $segment) {
                    $values[$segment] = round((float) collect($rows)
                        ->where('checkout', $checkout)
                        ->where('issuer', $issuer)
                        ->where('segmentKey', $segment)
                        ->sum('amount'), 2);
                }

                return [
                    'issuer' => $issuer,
                    'values' => $values,
                    'total' => round((float) array_sum($values), 2),
                ];
            })
            ->values()
            ->all();
    }

    private function salvadorDepartmentId(string $department): ?string
    {
        $normalized = str($department)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        $id = match ($normalized) {
            'ahuachapan' => 1,
            'cabanas' => 2,
            'chalatenango' => 3,
            'cuscatlan' => 4,
            'la libertad' => 5,
            'la paz' => 6,
            'la union' => 7,
            'morazan' => 8,
            'san miguel' => 9,
            'san salvador' => 10,
            'saint ana', 'santa ana' => 11,
            'san vicente' => 12,
            'sonsonate' => 13,
            'usulutan' => 14,
            default => null,
        };

        return $id !== null ? str_pad((string) $id, 2, '0', STR_PAD_LEFT) : null;
    }

    private function segmentSort(string $key): string
    {
        return match ($key) {
            'sv_web' => '01',
            'sv_app' => '02',
            'gt' => '03',
            'cr' => '04',
            'hn' => '05',
            default => '99'.$key,
        };
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

    private function stores(int $countryId): array
    {
        return DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId)
            ->orderBy('tie_nombre')
            ->get()
            ->map(fn ($store) => [
                'storeId' => (int) $store->tie_id,
                'storeCode' => (string) $store->tie_codigo,
                'store' => trim((string) $store->tie_nombre).' ('.(string) $store->tie_codigo.')',
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

    /**
     * @return array<int, string>
     */
    private function statuses(mixed $value): array
    {
        $rawStatuses = is_array($value)
            ? $value
            : explode(',', (string) $value);

        $statuses = collect($rawStatuses)
            ->map(fn ($status) => strtoupper(trim((string) $status)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($statuses === []) {
            return [];
        }

        $invalid = array_values(array_diff($statuses, self::PROCESSED_ORDER_STATUSES));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'statuses' => 'Uno o mas estados de pedido no son validos.',
            ]);
        }

        return $statuses;
    }
}
