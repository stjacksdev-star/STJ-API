<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use App\Support\StorefrontProductExclusions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontPromotionLandingService
{
    private const TIMEZONE = 'America/El_Salvador';

    public function __construct(
        private readonly ProductListAvailabilityService $availabilityService,
        private readonly StorefrontPromotionResolver $promotionResolver,
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
            ->where('schedule.pho_fin', '>', $now)
            ->whereIn('schedule.pho_estado', ['ACTIVO', 'PENDIENTE'])
            ->select([
                'promotion.prm_id',
                'promotion.prm_nombre',
                'promotion.prm_nombre_comercial',
                'promotion.prm_tipo',
                'promotion.prm_tipo_promocion',
                'promotion.prm_porcentaje',
                'promotion.prm_precio',
                'promotion.prm_restriccion',
                'promotion.prm_tipo_checkout',
                'promotion.prm_alcance_tienda',
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

        $scope = $this->promotionScope($promotion, $filters);
        $brand = trim((string) ($filters['brand'] ?? ''));
        $gender = trim((string) ($filters['gender'] ?? ''));
        $sort = trim((string) ($filters['sort'] ?? 'featured')) ?: 'featured';
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

        if ($sort === 'featured') {
            $this->applyLocalAvailabilitySort(
                $baseQuery,
                (int) $promotion->pai_id,
                (string) $promotion->pai_codigo,
                $filters,
            );
        }

        $this->applySort($baseQuery, $sort, $promotion);

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
                'category.cat_id',
                'category.cat_nombre',
                'subcategory.sca_id',
                'subcategory.sca_nombre',
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $availability = $this->availabilityService->summarize(
            strtolower((string) $promotion->pai_codigo),
            $paginator->getCollection()->all(),
            $this->activeStoreCode(
                (int) $promotion->pai_id,
                (string) $promotion->pai_codigo,
                $filters,
            ),
        );
        $availabilityBySku = $availability['availabilityBySku'] ?? [];
        $currency = $this->currency((string) $promotion->pai_codigo);
        $checkoutType = $this->checkoutType($filters['checkoutType'] ?? 'DOMICILIO');
        $resolvedByProduct = collect();
        if ($paginator->getCollection()->isNotEmpty()) {
            $resolution = $this->promotionResolver->resolve([
                'countryId' => (int) $promotion->pai_id,
                'checkoutType' => $checkoutType,
                'storeCode' => $filters['storeCode'] ?? null,
                'currencySymbol' => $this->currencySymbol((string) $promotion->pai_codigo),
                'promotionId' => (int) $promotion->prm_id,
                'includeUntriggered' => true,
                'lines' => $paginator->getCollection()->map(fn (object $product) => [
                    'key' => (string) $product->pro_id,
                    'productId' => (int) $product->pro_id,
                    'quantity' => 1,
                    'unitPrice' => (float) $product->ppa_precio,
                ])->all(),
            ]);
            $resolvedByProduct = collect($resolution['lines'])->keyBy('productId');
        }

        $products = $paginator->getCollection()
            ->map(function (object $product) use ($availabilityBySku, $currency, $resolvedByProduct) {
                $sku = trim((string) $product->pro_codigo);
                $stock = $availabilityBySku[$sku] ?? null;
                $regularPrice = (float) $product->ppa_precio;
                $resolved = $resolvedByProduct->get((int) $product->pro_id);
                $promotion = $resolved['promotion'] ?? null;
                $finalPrice = (float) ($resolved['finalTotal'] ?? $regularPrice);
                $discount = $promotion['discountPercentage'] ?? null;
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
                    'categoryId' => (int) $product->cat_id,
                    'subcategoryId' => (int) $product->sca_id,
                    'subcategory' => trim((string) $product->sca_nombre),
                    'description' => $description,
                    'sizes' => trim((string) $product->pro_tallas),
                    'imageUrl' => StorefrontImageUrl::image((string) $product->pro_thumbs, 'p400'),
                    'price' => $finalPrice,
                    'previousPrice' => $finalPrice < $regularPrice ? $regularPrice : null,
                    'discountPercentage' => $discount,
                    'currency' => $currency,
                    'promoName' => $promotion['displayLabel'] ?? '',
                    'badge' => $promotion['displayLabel'] ?? 'Promocion',
                    'promotion' => $promotion,
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
                'checkoutType' => (string) $promotion->prm_tipo_checkout,
                'storeScope' => $promotion->prm_alcance_tienda,
                'scope' => $scope,
                'headerImage' => StorefrontImageUrl::asset((string) $promotion->prm_encabezado),
                'startsAt' => $promotion->pho_inicio,
                'endsAt' => $promotion->pho_fin,
            ],
            'products' => $products,
            'filters' => [
                ...$filterOptions,
                'sorts' => [
                    ['value' => 'featured', 'label' => 'Recomendados'],
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

    private function promotionScope(object $promotion, array $filters): array
    {
        $checkoutType = strtoupper(trim((string) $promotion->prm_tipo_checkout));
        $storeScope = strtoupper(trim((string) $promotion->prm_alcance_tienda));
        $selectedStoresOnly = $storeScope === 'SELECCIONADAS' && in_array($checkoutType, ['', 'TODO', 'T'], true);
        $currentCheckout = $this->checkoutType($filters['checkoutType'] ?? 'DOMICILIO');
        $currentStoreCode = trim((string) ($filters['storeCode'] ?? ''));
        $stores = collect();

        if ($selectedStoresOnly) {
            $stores = DB::table('stj_promociones_tienda as promotion_store')
                ->join('stj_tiendas as store', 'store.tie_id', '=', 'promotion_store.prt_tienda')
                ->where('promotion_store.prt_promocion', $promotion->prm_id)
                ->where('store.tie_pais', $promotion->pai_id)
                ->orderBy('store.tie_nombre')
                ->get(['store.tie_id', 'store.tie_codigo', 'store.tie_nombre', 'store.tie_direccion', 'store.tie_horario'])
                ->map(fn (object $store) => [
                    'id' => (int) $store->tie_id,
                    'code' => trim((string) $store->tie_codigo),
                    'name' => trim((string) $store->tie_nombre),
                    'address' => trim((string) $store->tie_direccion),
                    'schedule' => trim(strip_tags((string) $store->tie_horario)),
                ])
                ->values();
        }

        if ($checkoutType === 'D') {
            $headline = 'Promoción válida para compras a domicilio';
        } elseif ($selectedStoresOnly) {
            $headline = 'Promoción válida en tiendas seleccionadas';
        } elseif ($checkoutType === 'T') {
            $headline = 'Promoción válida en todas nuestras tiendas';
        } else {
            $headline = 'Promoción válida para compras a domicilio y en todas nuestras tiendas';
        }

        $message = null;
        $eligible = true;
        if (($checkoutType === 'T' || $selectedStoresOnly) && $currentCheckout === 'DOMICILIO') {
            $eligible = false;
            $message = 'Esta promoción está disponible únicamente para compras en tiendas físicas.';
        } elseif ($checkoutType === 'D' && $currentCheckout === 'TIENDA') {
            $eligible = false;
            $message = 'Esta promoción está disponible únicamente para compras a domicilio.';
        } elseif ($selectedStoresOnly && $currentStoreCode !== '' && ! $stores->contains('code', $currentStoreCode)) {
            $eligible = false;
            $message = 'Esta promoción no aplica en la tienda seleccionada.';
        }

        return [
            'headline' => $headline,
            'storeCountLabel' => $stores->isNotEmpty() ? 'Válida en '.$stores->count().' '.($stores->count() === 1 ? 'tienda' : 'tiendas') : null,
            'eligibleForCurrentContext' => $eligible,
            'contextMessage' => $message,
            'stores' => $stores->all(),
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
        StorefrontProductExclusions::apply($query);

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

    private function applySort(Builder $query, string $sort, object $promotion): void
    {
        $fixedPercentage = (float) ($promotion->prm_porcentaje ?? 0);
        $hasFixedPercentage = $fixedPercentage > 0;
        $discountExpression = (string) $promotion->prm_tipo !== 'TODO'
            ? 'COALESCE(promotion_product.ppr_descuento, 0)'
            : '0';

        match ($sort) {
            'discount_asc' => $hasFixedPercentage
                ? $query->orderByDesc('product.pro_registro')
                : $query->orderByRaw("{$discountExpression} ASC")->orderByDesc('product.pro_registro'),
            'newest' => $query->orderByDesc('product.pro_registro'),
            'price_asc' => $hasFixedPercentage
                ? $query->orderByRaw('(country_product.ppa_precio * ?) ASC', [1 - ($fixedPercentage / 100)])
                : $query->orderByRaw("(country_product.ppa_precio * (1 - {$discountExpression} / 100)) ASC"),
            'price_desc' => $hasFixedPercentage
                ? $query->orderByRaw('(country_product.ppa_precio * ?) DESC', [1 - ($fixedPercentage / 100)])
                : $query->orderByRaw("(country_product.ppa_precio * (1 - {$discountExpression} / 100)) DESC"),
            default => $hasFixedPercentage
                ? $query->orderByDesc('product.pro_registro')
                : $query->orderByRaw("{$discountExpression} DESC")->orderByDesc('product.pro_registro'),
        };

        $query->orderBy('product.pro_id');
    }

    private function applyLocalAvailabilitySort(
        Builder $query,
        int $countryId,
        string $countryCode,
        array $filters,
    ): void {
        $storeCode = $this->activeStoreCode($countryId, $countryCode, $filters);
        if ($storeCode === '') {
            return;
        }

        $inventory = DB::table('stj_inventario')
            ->where('inv_pais', $countryId)
            ->where('inv_tienda', $storeCode)
            ->select([
                'inv_codigo',
                DB::raw('SUM(CASE WHEN inv_cantidad > 0 THEN inv_cantidad ELSE 0 END) as stock_total'),
            ])
            ->groupBy('inv_codigo');

        $query
            ->leftJoinSub($inventory, 'inventory_stock', function ($join) {
                $join->on('inventory_stock.inv_codigo', '=', 'product.pro_codigo');
            })
            ->orderByRaw('COALESCE(inventory_stock.stock_total, 0) DESC');
    }

    private function activeStoreCode(int $countryId, string $countryCode, array $filters): string
    {
        $requested = trim((string) ($filters['storeCode'] ?? ''));
        if ($requested === '') {
            $country = strtolower($countryCode);
            $checkoutType = $this->checkoutType($filters['checkoutType'] ?? 'DOMICILIO');
            $requested = trim((string) config(
                $checkoutType === 'DOMICILIO'
                    ? "inventory.domicilio_store_by_country.{$country}"
                    : "inventory.default_store_by_country.{$country}",
                '',
            ));
        }

        if ($requested === '') {
            return '';
        }

        $exists = DB::table('stj_tiendas')
            ->where('tie_pais', $countryId)
            ->where('tie_codigo', $requested)
            ->exists();

        return $exists ? $requested : '';
    }

    private function checkoutType(mixed $value): string
    {
        return in_array(strtoupper(trim((string) $value)), ['T', 'TIENDA'], true)
            ? 'TIENDA'
            : 'DOMICILIO';
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

    private function currencySymbol(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'GT' => 'Q',
            'CR' => '₡',
            'HN' => 'L',
            'DO' => 'RD$',
            default => '$',
        };
    }
}
