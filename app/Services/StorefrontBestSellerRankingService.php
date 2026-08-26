<?php

namespace App\Services;

use App\Support\StorefrontProductExclusions;
use App\Support\StorefrontImageUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StorefrontBestSellerRankingService
{
    public function __construct(
        private readonly ProductListAvailabilityService $productListAvailability,
        private readonly ?StorefrontPromotionResolver $promotionResolver = null,
    ) {}

    public function paginate(string $country, int $days, int $perPage = 15): LengthAwarePaginator
    {
        $countryRow = $this->country($country);
        $period = app(ProductBestSellerCalculator::class)->period($days);
        $currency = $this->currency((string) $countryRow->pai_codigo);
        $perPage = min(100, max(1, $perPage));

        $paginator = $this->query((int) $countryRow->pai_id, $period)
            ->paginate($perPage)
            ->appends(['period' => $days, 'per_page' => $perPage]);

        $availability = $this->productListAvailability->summarize(
            (string) $countryRow->pai_codigo,
            $paginator->getCollection()->all(),
        );
        $availabilityBySku = $availability['availabilityBySku'] ?? [];
        $commercialByProduct = $this->commercialByProduct($paginator->getCollection(), (int) $countryRow->pai_id);

        $paginator->through(fn (object $row) => $this->normalize(
            $row,
            $currency,
            $availabilityBySku[trim((string) $row->pro_codigo)] ?? null,
            $commercialByProduct->get((int) $row->pro_id),
        ));

        return $paginator;
    }

    /**
     * Devuelve todo el ranking sin LIMIT para las landings con filtros locales.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(string $country, int $days): array
    {
        $countryRow = $this->country($country);
        $period = app(ProductBestSellerCalculator::class)->period($days);
        $currency = $this->currency((string) $countryRow->pai_codigo);

        $rows = $this->query((int) $countryRow->pai_id, $period)->get();
        $availability = $this->productListAvailability->summarize(
            (string) $countryRow->pai_codigo,
            $rows->all(),
        );
        $availabilityBySku = $availability['availabilityBySku'] ?? [];
        $commercialByProduct = $this->commercialByProduct($rows, (int) $countryRow->pai_id);

        return $rows
            ->map(fn (object $row) => $this->normalize(
                $row,
                $currency,
                $availabilityBySku[trim((string) $row->pro_codigo)] ?? null,
                $commercialByProduct->get((int) $row->pro_id),
            ))
            ->filter(fn (array $product) => $product['hasStock'])
            ->unique('id')
            ->values()
            ->all();
    }

    private function query(int $countryId, string $period): Builder
    {
        $query = DB::table('stj_producto_metricas as metrics')
            ->join('stj_productos as product', 'product.pro_id', '=', 'metrics.pme_producto')
            ->join('stj_producto_pais as country_product', function ($join) {
                $join->on('country_product.ppa_producto', '=', 'product.pro_id')
                    ->on('country_product.ppa_pais', '=', 'metrics.pme_pais');
            })
            ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'product.pro_categoria')
            ->where('metrics.pme_pais', $countryId)
            ->where('metrics.pme_periodo', $period)
            ->where('product.pro_estatus', 'ACTIVO')
            ->where('country_product.ppa_estado', 'ACTIVO')
            ->orderBy('metrics.pme_ranking_ventas');
        StorefrontProductExclusions::apply($query);

        return $query
            ->select([
                'product.pro_id',
                'product.pro_codigo',
                'product.pro_nombre',
                'product.pro_marca',
                'product.pro_oc_genero',
                'product.pro_tallas',
                'product.pro_thumbs',
                'country_product.ppa_precio',
                'category.cat_nombre',
                'metrics.pme_ventas_unidades',
                'metrics.pme_ventas_pedidos',
                'metrics.pme_monto_vendido',
                'metrics.pme_ranking_ventas',
                'metrics.pme_fecha_calculo',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(object $row, string $currency, ?array $availability = null, ?array $commercial = null): array
    {
        $regularPrice = (float) $row->ppa_precio;
        $promotion = $commercial['promotion'] ?? null;
        $finalPrice = (float) ($commercial['finalTotal'] ?? $regularPrice);

        return [
            'id' => (int) $row->pro_id,
            'slug' => Str::slug((string) $row->pro_nombre).'-'.$row->pro_id,
            'sku' => (string) $row->pro_codigo,
            'name' => (string) $row->pro_nombre,
            'brand' => (string) ($row->pro_marca ?? ''),
            'group' => (string) ($row->pro_oc_genero ?? ''),
            'category' => (string) ($row->cat_nombre ?? ''),
            'imageUrl' => StorefrontImageUrl::image((string) $row->pro_thumbs, 'p400'),
            'price' => $finalPrice,
            'previousPrice' => $finalPrice < $regularPrice ? $regularPrice : null,
            'currency' => $currency,
            'promoName' => (string) ($promotion['displayLabel'] ?? ''),
            'promotion' => $promotion,
            'badge' => '#'.(int) $row->pme_ranking_ventas,
            'availableSizes' => $availability['availableSizes'] ?? [],
            'hasStock' => (bool) ($availability['hasStock'] ?? false),
            'stockTotal' => (int) ($availability['totalQuantity'] ?? 0),
            'sales' => [
                'units' => (int) $row->pme_ventas_unidades,
                'orders' => (int) $row->pme_ventas_pedidos,
                'amount' => (float) $row->pme_monto_vendido,
                'rank' => (int) $row->pme_ranking_ventas,
                'calculatedAt' => (string) $row->pme_fecha_calculo,
            ],
        ];
    }

    private function commercialByProduct($rows, int $countryId)
    {
        if ($rows->isEmpty() || ! Schema::hasTable('stj_promociones') || ! Schema::hasTable('stj_promociones_horario')) {
            return collect();
        }

        $resolver = $this->promotionResolver ?? app(StorefrontPromotionResolver::class);
        $resolution = $resolver->resolve([
            'countryId' => $countryId,
            'checkoutType' => 'DOMICILIO',
            'includeUntriggered' => true,
            'lines' => $rows->map(fn (object $row) => [
                'key' => (string) $row->pro_id,
                'productId' => (int) $row->pro_id,
                'quantity' => 1,
                'unitPrice' => (float) $row->ppa_precio,
            ])->all(),
        ]);

        return collect($resolution['lines'])->keyBy('productId');
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
