<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class ProductPerformanceReportService
{
    public function report(array $filters): array
    {
        $country = DB::table('stj_paises')->whereRaw('UPPER(pai_codigo) = ?', [strtoupper($filters['country'])])->first();
        abort_unless($country, 404, 'Pais no encontrado.');

        $base = DB::table('stj_producto_metricas as met')
            ->join('stj_productos as pro', 'pro.pro_id', '=', 'met.pme_producto')
            ->leftJoin('stj_categorias as cat', 'cat.cat_id', '=', 'pro.pro_categoria')
            ->where('met.pme_pais', $country->pai_id)
            ->where('met.pme_periodo', $filters['period']);

        $brands = (clone $base)->whereNotNull('pro.pro_marca')->distinct()->orderBy('pro.pro_marca')->pluck('pro.pro_marca')->values();
        $categories = (clone $base)->whereNotNull('cat.cat_id')->distinct()->orderBy('cat.cat_nombre')->get(['cat.cat_id as id', 'cat.cat_nombre as name']);

        $query = clone $base;
        $query->when($filters['brand'] ?? null, fn ($q, $brand) => $q->where('pro.pro_marca', $brand))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('cat.cat_id', $category))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = '%'.trim($search).'%';
                $q->where(fn ($nested) => $nested->where('pro.pro_nombre', 'like', $term)->orWhere('pro.pro_codigo', 'like', $term));
            });

        $summaryQuery = clone $query;
        $summary = $summaryQuery->selectRaw('COUNT(*) as products, COALESCE(SUM(met.pme_ventas_unidades), 0) as sales, COALESCE(SUM(met.pme_monto_vendido), 0) as amount, COALESCE(SUM(met.pme_vistas), 0) as views, COALESCE(SUM(met.pme_favoritos), 0) as favorites, COALESCE(SUM(met.pme_agregados_carrito), 0) as cartAdds, MAX(met.pme_fecha_calculo) as calculatedAt')->first();

        match ($filters['tab']) {
            'sales' => $query->where('met.pme_ventas_unidades', '>', 0)->orderByDesc('met.pme_ventas_unidades')->orderByDesc('met.pme_monto_vendido'),
            'views' => $query->where('met.pme_vistas', '>', 0)->orderByDesc('met.pme_vistas'),
            'favorites' => $query->where('met.pme_favoritos', '>', 0)->orderByDesc('met.pme_favoritos'),
            'cart' => $query->where('met.pme_agregados_carrito', '>', 0)->orderByDesc('met.pme_agregados_carrito'),
            default => $query->orderByDesc('met.pme_ventas_unidades')->orderByDesc('met.pme_vistas'),
        };

        $page = $query->select([
            'pro.pro_id as productId', 'pro.pro_codigo as code', 'pro.pro_nombre as name', 'pro.pro_marca as brand',
            'cat.cat_nombre as category', 'met.pme_ventas_unidades as sales', 'met.pme_ventas_pedidos as orders',
            'met.pme_monto_vendido as amount', 'met.pme_vistas as views', 'met.pme_favoritos as favorites',
            'met.pme_agregados_carrito as cartAdds', 'met.pme_ranking_ventas as salesRank', 'met.pme_ranking_vistas as viewsRank',
            'met.pme_fecha_calculo as calculatedAt',
        ])->paginate($filters['perPage'], ['*'], 'page', $filters['page']);

        $rows = collect($page->items())->map(function ($row) {
            $item = (array) $row;
            $item['conversionRate'] = (int) $row->views > 0 ? round(((int) $row->sales / (int) $row->views) * 100, 2) : 0;
            return $item;
        });

        return [
            'rows' => $rows,
            'summary' => [
                'products' => (int) $summary->products, 'sales' => (int) $summary->sales,
                'amount' => round((float) $summary->amount, 2), 'views' => (int) $summary->views,
                'favorites' => (int) $summary->favorites, 'cartAdds' => (int) $summary->cartAdds,
                'calculatedAt' => $summary->calculatedAt,
            ],
            'filters' => ['brands' => $brands, 'categories' => $categories],
            'country' => ['id' => (int) $country->pai_id, 'code' => strtoupper($country->pai_codigo), 'name' => $country->pai_nombre],
            'pagination' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ];
    }
}
