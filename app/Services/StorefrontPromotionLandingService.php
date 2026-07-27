<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontPromotionLandingService
{
    private const TIMEZONE = 'America/El_Salvador';

    public function __construct(
        private readonly ProductListAvailabilityService $availabilityService,
    ) {}

    public function find(string $countryCode, int $promotionId, array $filters = []): ?array
    {
        $now = Carbon::now(self::TIMEZONE)->format('Y-m-d H:i:s');
        $promotion = DB::table('stj_promociones as promotion')
            ->join('stj_paises as country', 'country.pai_id', '=', 'promotion.prm_pais')
            ->join('stj_promociones_horario as schedule', 'schedule.pho_promocion', '=', 'promotion.prm_id')
            ->where('promotion.prm_id', $promotionId)
            ->whereRaw('UPPER(country.pai_codigo) = ?', [strtoupper($countryCode)])
            ->where('promotion.prm_estado', 'EN-PROCESO')
            ->where('schedule.pho_tipo', 'NORMAL')
            ->where('schedule.pho_inicio', '<=', $now)
            ->where('schedule.pho_fin', '>=', $now)
            ->whereIn('schedule.pho_estado', ['ACTIVO', 'PENDIENTE'])
            ->select([
                'promotion.prm_id',
                'promotion.prm_nombre',
                'promotion.prm_nombre_comercial',
                'promotion.prm_tipo',
                'promotion.prm_tipo_promocion',
                'promotion.prm_encabezado',
                'country.pai_id',
                'country.pai_codigo',
                'schedule.pho_inicio',
                'schedule.pho_fin',
            ])
            ->first();

        if (! $promotion) {
            return null;
        }

        $brand = trim((string) ($filters['brand'] ?? ''));
        $gender = trim((string) ($filters['gender'] ?? ''));
        $sort = trim((string) ($filters['sort'] ?? 'discount_desc')) ?: 'discount_desc';
        $perPage = (int) ($filters['perPage'] ?? 24);
        $page = (int) ($filters['page'] ?? 1);
        $baseQuery = $this->productsQuery($promotion);

        $filterOptions = [
            'brands' => $this->options(clone $baseQuery, 'product.pro_marca'),
            'genders' => $this->options(clone $baseQuery, 'product.pro_oc_genero'),
        ];

        if ($brand !== '') {
            $baseQuery->where('product.pro_marca', $brand);
        }
        if ($gender !== '') {
            $baseQuery->where('product.pro_oc_genero', $gender);
        }

        $this->applySort($baseQuery, $sort);

        $paginator = $baseQuery
            ->select([
                'product.pro_id',
                'product.pro_codigo',
                'product.pro_nombre',
                'product.pro_descripcion',
                'product.pro_marca',
                'product.pro_oc_genero',
                'product.pro_tallas',
                'product.pro_thumbs',
                'product.pro_registro',
                'country_product.ppa_precio',
                'country_product.ppa_descuento',
                'country_product.ppa_promo_nombre',
                'category.cat_nombre',
                'subcategory.sca_nombre',
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $availability = $this->availabilityService->summarize(
            strtolower((string) $promotion->pai_codigo),
            $paginator->getCollection()->all(),
        );
        $availabilityBySku = $availability['availabilityBySku'] ?? [];
        $currency = $this->currency((string) $promotion->pai_codigo);

        $products = $paginator->getCollection()
            ->map(function (object $product) use ($availabilityBySku, $currency) {
                $sku = trim((string) $product->pro_codigo);
                $stock = $availabilityBySku[$sku] ?? null;
                $discount = max(0, min(100, (float) ($product->ppa_descuento ?? 0)));
                $regularPrice = (float) $product->ppa_precio;
                $category = trim((string) ($product->cat_nombre ?: 'Promocion'));
                $description = trim((string) $product->pro_descripcion)
                    ?: trim((string) ($product->sca_nombre ?: "Categoria {$category}"));

                return [
                    'id' => (int) $product->pro_id,
                    'slug' => Str::slug((string) $product->pro_nombre).'-'.$product->pro_id,
                    'sku' => $sku,
                    'name' => trim((string) $product->pro_nombre),
                    'brand' => trim((string) ($product->pro_marca ?: 'ST JACKS')),
                    'group' => trim((string) $product->pro_oc_genero),
                    'category' => $category,
                    'description' => $description,
                    'sizes' => trim((string) $product->pro_tallas),
                    'imageUrl' => StorefrontImageUrl::image((string) $product->pro_thumbs, 'p400'),
                    'price' => round($regularPrice * (1 - $discount / 100), 2),
                    'previousPrice' => $discount > 0 ? $regularPrice : null,
                    'discountPercentage' => $discount,
                    'currency' => $currency,
                    'promoName' => trim((string) $product->ppa_promo_nombre),
                    'badge' => $discount > 0 ? $this->formatDiscount($discount).' de descuento' : 'Promocion',
                    'availableSizes' => $stock['availableSizes'] ?? [],
                    'hasStock' => (bool) ($stock['hasStock'] ?? false),
                    'stockTotal' => (int) ($stock['totalQuantity'] ?? 0),
                ];
            })
            ->values()
            ->all();

        return [
            'promotion' => [
                'id' => (int) $promotion->prm_id,
                'name' => trim((string) $promotion->prm_nombre),
                'title' => trim((string) ($promotion->prm_nombre_comercial ?: $promotion->prm_nombre)),
                'slug' => Str::slug((string) ($promotion->prm_nombre_comercial ?: $promotion->prm_nombre)),
                'type' => (string) $promotion->prm_tipo,
                'promotionType' => (string) $promotion->prm_tipo_promocion,
                'headerImage' => StorefrontImageUrl::asset((string) $promotion->prm_encabezado),
                'startsAt' => $promotion->pho_inicio,
                'endsAt' => $promotion->pho_fin,
            ],
            'products' => $products,
            'filters' => [
                ...$filterOptions,
                'sorts' => [
                    ['value' => 'discount_desc', 'label' => 'Mayor descuento'],
                    ['value' => 'discount_asc', 'label' => 'Menor descuento'],
                    ['value' => 'newest', 'label' => 'Mas recientes'],
                    ['value' => 'price_asc', 'label' => 'Precio menor'],
                    ['value' => 'price_desc', 'label' => 'Precio mayor'],
                ],
                'active' => compact('brand', 'gender', 'sort'),
            ],
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'availability' => [
                'activeStoreCode' => $availability['activeStoreCode'] ?? null,
                'usedSource' => $availability['usedSource'] ?? null,
            ],
        ];
    }

    private function productsQuery(object $promotion): Builder
    {
        $query = DB::table('stj_producto_pais as country_product')
            ->join('stj_productos as product', 'product.pro_id', '=', 'country_product.ppa_producto')
            ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'product.pro_categoria')
            ->leftJoin('stj_sub_categorias as subcategory', 'subcategory.sca_id', '=', 'product.pro_sub_categoria')
            ->where('country_product.ppa_pais', (int) $promotion->pai_id)
            ->where('country_product.ppa_estado', 'ACTIVO')
            ->where('product.pro_estatus', 'ACTIVO');

        if ((string) $promotion->prm_tipo !== 'TODO') {
            $query
                ->join('stj_promociones_producto as promotion_product', function ($join) use ($promotion) {
                    $join
                        ->on('promotion_product.ppr_producto', '=', 'product.pro_id')
                        ->where('promotion_product.ppr_promocion', '=', (int) $promotion->prm_id);
                });
        }

        return $query;
    }

    private function options(Builder $query, string $column): array
    {
        return $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->select([$column, DB::raw('COUNT(DISTINCT product.pro_id) as total')])
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn (object $row) => [
                'value' => trim((string) data_get($row, Str::afterLast($column, '.'))),
                'label' => trim((string) data_get($row, Str::afterLast($column, '.'))),
                'count' => (int) $row->total,
            ])
            ->filter(fn (array $option) => $option['value'] !== '')
            ->values()
            ->all();
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'discount_asc' => $query->orderByRaw('COALESCE(country_product.ppa_descuento, 0) ASC')->orderByDesc('product.pro_registro'),
            'newest' => $query->orderByDesc('product.pro_registro'),
            'price_asc' => $query->orderByRaw('(country_product.ppa_precio * (1 - COALESCE(country_product.ppa_descuento, 0) / 100)) ASC'),
            'price_desc' => $query->orderByRaw('(country_product.ppa_precio * (1 - COALESCE(country_product.ppa_descuento, 0) / 100)) DESC'),
            default => $query->orderByRaw('COALESCE(country_product.ppa_descuento, 0) DESC')->orderByDesc('product.pro_registro'),
        };

        $query->orderBy('product.pro_id');
    }

    private function formatDiscount(float $discount): string
    {
        return rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.').'%';
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
