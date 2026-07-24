<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontBestSellerRankingService
{
    public function paginate(string $country, int $days, int $perPage = 15): LengthAwarePaginator
    {
        $countryRow = $this->country($country);
        $period = app(ProductBestSellerCalculator::class)->period($days);

        $paginator = DB::table('stj_producto_metricas as metrics')
            ->join('stj_productos as product', 'product.pro_id', '=', 'metrics.pme_producto')
            ->join('stj_producto_pais as country_product', function ($join) {
                $join->on('country_product.ppa_producto', '=', 'product.pro_id')
                    ->on('country_product.ppa_pais', '=', 'metrics.pme_pais');
            })
            ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'product.pro_categoria')
            ->where('metrics.pme_pais', $countryRow->pai_id)
            ->where('metrics.pme_periodo', $period)
            ->where('product.pro_estatus', 'ACTIVO')
            ->where('country_product.ppa_estado', 'ACTIVO')
            ->orderBy('metrics.pme_ranking_ventas')
            ->select([
                'product.pro_id',
                'product.pro_codigo',
                'product.pro_nombre',
                'product.pro_marca',
                'product.pro_thumbs',
                'country_product.ppa_precio',
                'country_product.ppa_descuento',
                'country_product.ppa_promo_nombre',
                'category.cat_nombre',
                'metrics.pme_ventas_unidades',
                'metrics.pme_ventas_pedidos',
                'metrics.pme_monto_vendido',
                'metrics.pme_ranking_ventas',
                'metrics.pme_fecha_calculo',
            ])
            ->paginate(min(100, max(1, $perPage)))
            ->appends([
                'period' => $days,
                'per_page' => min(100, max(1, $perPage)),
            ]);

        $currency = $this->currency((string) $countryRow->pai_codigo);
        $paginator->through(function (object $row) use ($currency) {
            $discount = max(0, min(100, (float) ($row->ppa_descuento ?? 0)));
            $regularPrice = (float) $row->ppa_precio;

            return [
                'id' => (int) $row->pro_id,
                'slug' => Str::slug((string) $row->pro_nombre).'-'.$row->pro_id,
                'sku' => (string) $row->pro_codigo,
                'name' => (string) $row->pro_nombre,
                'brand' => (string) ($row->pro_marca ?? ''),
                'category' => (string) ($row->cat_nombre ?? ''),
                'imageUrl' => StorefrontImageUrl::image((string) $row->pro_thumbs, 'p400'),
                'price' => round($regularPrice * (1 - $discount / 100), 2),
                'previousPrice' => $discount > 0 ? $regularPrice : null,
                'currency' => $currency,
                'promoName' => (string) ($row->ppa_promo_nombre ?? ''),
                'sales' => [
                    'units' => (int) $row->pme_ventas_unidades,
                    'orders' => (int) $row->pme_ventas_pedidos,
                    'amount' => (float) $row->pme_monto_vendido,
                    'rank' => (int) $row->pme_ranking_ventas,
                    'calculatedAt' => (string) $row->pme_fecha_calculo,
                ],
            ];
        });

        return $paginator;
    }

    private function country(string $country): object
    {
        $query = DB::table('stj_paises')->select(['pai_id', 'pai_codigo']);

        $row = ctype_digit($country)
            ? $query->where('pai_id', (int) $country)->first()
            : $query->where('pai_codigo', strtoupper($country))->first();

        if (! $row || ! in_array((int) $row->pai_id, ProductBestSellerCalculator::COUNTRIES, true)) {
            abort(404, 'País no disponible para el ranking.');
        }

        return $row;
    }

    private function currency(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'GT' => 'GTQ',
            'CR' => 'CRC',
            'DO' => 'DOP',
            'HN' => 'HNL',
            default => 'USD',
        };
    }
}
