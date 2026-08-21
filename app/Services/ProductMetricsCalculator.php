<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductMetricsCalculator
{
    public const COUNTRIES = [1, 2, 3, 5, 7];

    public const PERIODS = [7, 14, 30, 365];

    /** @return Collection<int, object> */
    public function salesFor(int $countryId, int $days): Collection
    {
        $this->assertSupported($countryId, $days);

        return DB::table('stj_pedidos as ped')
            ->join('stj_pedidos_pago as pag', function ($join) {
                $join->on('pag.ppa_pedido', '=', 'ped.ped_id')->where('pag.ppa_estado', 'APROBADA');
            })
            ->join('stj_pedidos_detalle as det', function ($join) {
                $join->on('det.car_ref', '=', 'pag.ppa_ref')->on('det.car_pais', '=', 'ped.ped_id_pais');
            })
            ->join('stj_productos as pro', 'pro.pro_id', '=', 'det.car_producto')
            ->join('stj_producto_pais as ppa', function ($join) {
                $join->on('ppa.ppa_producto', '=', 'pro.pro_id')->on('ppa.ppa_pais', '=', 'ped.ped_id_pais');
            })
            ->where('ped.ped_id_pais', $countryId)
            ->where('det.car_accion', 'AGREGADO')
            ->where('ppa.ppa_estado', 'ACTIVO')
            ->whereNotIn('det.car_producto', [5645, 5646])
            ->whereRaw($this->dateFilter('pag.ppa_fecha'), [$days])
            ->groupBy('det.car_producto', 'ped.ped_id_pais')
            ->orderByDesc('unidades_vendidas')
            ->orderByDesc('monto_vendido')
            ->selectRaw('det.car_producto AS producto_id, ped.ped_id_pais AS pais,
                SUM(det.car_cantidad) AS unidades_vendidas,
                COUNT(DISTINCT ped.ped_id) AS pedidos_diferentes,
                ROUND(SUM(ROUND(det.car_precio * det.car_cantidad * (1 - COALESCE(det.car_descuento, 0) / 100), 2)), 2) AS monto_vendido')
            ->get();
    }

    /** @return Collection<int, object> */
    public function viewsFor(int $countryId, int $days): Collection
    {
        $this->assertSupported($countryId, $days);

        return DB::table('stj_cliente_eventos')
            ->where('cev_tipo', 'PRODUCT_VIEW')
            ->where('cev_pais_id', $countryId)
            ->whereNotNull('cev_producto_id')
            ->whereRaw($this->dateFilter('cev_ocurrido_en'), [$days])
            ->groupBy('cev_producto_id', 'cev_pais_id')
            ->selectRaw('cev_producto_id AS producto_id, cev_pais_id AS pais, COUNT(*) AS vistas')
            ->get();
    }

    /** @return Collection<int, object> */
    public function favoritesFor(int $countryId, int $days): Collection
    {
        $this->assertSupported($countryId, $days);

        return DB::table('stj_favoritos')
            ->where('fav_pais', $countryId)
            ->whereNotNull('fav_producto')
            ->whereRaw($this->dateFilter('fav_created_at'), [$days])
            ->groupBy('fav_producto', 'fav_pais')
            ->selectRaw('fav_producto AS producto_id, fav_pais AS pais, COUNT(*) AS favoritos')
            ->get();
    }

    /** @return Collection<int, object> */
    public function cartAddsFor(int $countryId, int $days): Collection
    {
        $this->assertSupported($countryId, $days);

        return DB::table('stj_cliente_eventos')
            ->where('cev_tipo', 'ADD_TO_CART')
            ->where('cev_pais_id', $countryId)
            ->whereNotNull('cev_producto_id')
            ->whereRaw($this->dateFilter('cev_ocurrido_en'), [$days])
            ->groupBy('cev_producto_id', 'cev_pais_id')
            ->selectRaw('cev_producto_id AS producto_id, cev_pais_id AS pais, COUNT(*) AS agregados_carrito')
            ->get();
    }

    public function calculateAndStore(int $countryId, int $days): int
    {
        $sales = $this->salesFor($countryId, $days)->values();
        $views = $this->viewsFor($countryId, $days)->values();
        $favorites = $this->favoritesFor($countryId, $days)->values();
        $cartAdds = $this->cartAddsFor($countryId, $days)->values();
        $period = $this->period($days);
        $executedAt = CarbonImmutable::now()->startOfSecond();
        $salesByProduct = $sales->keyBy(fn (object $row) => (int) $row->producto_id);
        $viewsByProduct = $views->keyBy(fn (object $row) => (int) $row->producto_id);
        $favoritesByProduct = $favorites->keyBy(fn (object $row) => (int) $row->producto_id);
        $cartAddsByProduct = $cartAdds->keyBy(fn (object $row) => (int) $row->producto_id);
        $salesRank = $sales->mapWithKeys(fn (object $row, int $index) => [(int) $row->producto_id => $index + 1]);
        $viewRank = $views
            ->sort(fn (object $left, object $right) => [(int) $right->vistas, (int) $left->producto_id] <=> [(int) $left->vistas, (int) $right->producto_id])
            ->values()
            ->mapWithKeys(fn (object $row, int $index) => [(int) $row->producto_id => $index + 1]);
        $productIds = $salesByProduct->keys()->merge($viewsByProduct->keys())->merge($favoritesByProduct->keys())->merge($cartAddsByProduct->keys())
            ->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $payload = $productIds->map(function (int $productId) use ($salesByProduct, $viewsByProduct, $favoritesByProduct, $cartAddsByProduct, $salesRank, $viewRank, $countryId, $period, $executedAt) {
            $sale = $salesByProduct->get($productId);
            $view = $viewsByProduct->get($productId);
            $favorite = $favoritesByProduct->get($productId);
            $cartAdd = $cartAddsByProduct->get($productId);

            return [
                'pme_producto' => $productId,
                'pme_pais' => $countryId,
                'pme_periodo' => $period,
                'pme_ventas_unidades' => (int) ($sale->unidades_vendidas ?? 0),
                'pme_ventas_pedidos' => (int) ($sale->pedidos_diferentes ?? 0),
                'pme_monto_vendido' => round((float) ($sale->monto_vendido ?? 0), 2),
                'pme_ranking_ventas' => $salesRank->get($productId),
                'pme_vistas' => (int) ($view->vistas ?? 0),
                'pme_ranking_vistas' => $viewRank->get($productId),
                'pme_favoritos' => (int) ($favorite->favoritos ?? 0),
                'pme_agregados_carrito' => (int) ($cartAdd->agregados_carrito ?? 0),
                'pme_fecha_calculo' => $executedAt->toDateTimeString(),
            ];
        });

        DB::transaction(function () use ($payload, $productIds, $countryId, $period) {
            $payload->chunk(500)->each(fn (Collection $chunk) => DB::table('stj_producto_metricas')->upsert(
                $chunk->all(),
                ['pme_producto', 'pme_pais', 'pme_periodo'],
                ['pme_ventas_unidades', 'pme_ventas_pedidos', 'pme_monto_vendido', 'pme_ranking_ventas', 'pme_vistas', 'pme_ranking_vistas', 'pme_favoritos', 'pme_agregados_carrito', 'pme_fecha_calculo'],
            ));

            $obsolete = DB::table('stj_producto_metricas')->where('pme_pais', $countryId)->where('pme_periodo', $period);
            if ($productIds->isNotEmpty()) {
                $obsolete->whereNotIn('pme_producto', $productIds->all());
            }
            $obsolete->delete();
        });

        return $payload->count();
    }

    public function period(int $days): string
    {
        if (! in_array($days, self::PERIODS, true)) {
            throw new \InvalidArgumentException("El periodo {$days} no esta soportado.");
        }

        return $days === 365 ? 'ANUAL' : "{$days}D";
    }

    private function assertSupported(int $countryId, int $days): void
    {
        if (! in_array($countryId, self::COUNTRIES, true)) {
            throw new \InvalidArgumentException("El pais {$countryId} no esta soportado.");
        }
        $this->period($days);
    }

    private function dateFilter(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "{$column} >= date('now', '-' || ? || ' days')"
            : "{$column} >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    }
}
