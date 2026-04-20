<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontCatalogService
{
    private const LEGACY_HOST = 'https://stjacks.com';
    private const GROUP_MAPPINGS = [
        'girls' => ['Niñas'],
        'boys' => ['Niños'],
        'toddlers' => ['Toddler Niñas', 'Toddler Niños'],
        'babies' => ['Bebas', 'Bebos', 'Bebes Unisex'],
        'adults' => ['Damas', 'Caballeros'],
        'teens' => ['Teen Chicas', 'Teen Chicos'],
        'accessories' => ['Ropa Interior y Accesorios', 'Cuidado Personal', 'Otros'],
    ];

    public function __construct(
        private readonly ProductListAvailabilityService $productListAvailabilityService,
    ) {
    }

    public function forCountry(string $countryCode, ?string $query = null, array $filters = []): array
    {
        $country = $this->resolveCountry($countryCode);
        $trimmedQuery = trim((string) $query);
        $activeGroup = trim((string) ($filters['group'] ?? ''));
        $activeCategory = trim((string) ($filters['category'] ?? ''));
        $activeSort = trim((string) ($filters['sort'] ?? 'featured'));
        $promoOnly = filter_var($filters['promo'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $country) {
            return [
                'filters' => [
                    'groups' => $this->groupFilters(),
                    'categories' => [],
                    'sorts' => $this->sortFilters(),
                    'promotions' => $this->promotionFilters(),
                    'active' => [
                        'group' => $activeGroup,
                        'category' => $activeCategory,
                        'sort' => $activeSort,
                        'promo' => $promoOnly,
                    ],
                ],
                'products' => [],
                'search' => [
                    'query' => $trimmedQuery,
                    'total' => 0,
                ],
            ];
        }

        $baseQuery = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $country->pai_id)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO');

        if ($trimmedQuery !== '') {
            $baseQuery->where(function ($subQuery) use ($trimmedQuery) {
                $subQuery
                    ->where('p.pro_nombre', 'like', "%{$trimmedQuery}%")
                    ->orWhere('p.pro_codigo', 'like', "%{$trimmedQuery}%")
                    ->orWhere('p.pro_tags', 'like', "%{$trimmedQuery}%")
                    ->orWhere('p.pro_oc_categoria', 'like', "%{$trimmedQuery}%")
                    ->orWhere('sc.sca_nombre', 'like', "%{$trimmedQuery}%");
            });
        }

        $this->applyGroupFilter($baseQuery, $activeGroup);
        $this->applyPromoFilter($baseQuery, $promoOnly);

        $productsQuery = clone $baseQuery;
        $this->applyCategoryFilter($productsQuery, $activeCategory);
        $this->applySort($productsQuery, $activeSort);

        $rawProducts = $productsQuery
            ->select([
                'p.pro_id',
                'p.pro_codigo',
                'p.pro_thumbs',
                'p.pro_nombre',
                'p.pro_descripcion',
                'p.pro_marca',
                'p.pro_oc_categoria',
                'p.pro_tallas',
                'pp.ppa_precio',
                'pp.ppa_promo_nombre',
                'pp.ppa_es_popular',
                'sc.sca_nombre as subcategoria_nombre',
                'c.cat_nombre as categoria_nombre',
            ])
            ->orderByDesc('pp.ppa_es_popular')
            ->orderByDesc('p.pro_registro')
            ->limit(24)
            ->get();

        $availability = $this->productListAvailabilityService->summarize(
            strtolower((string) $country->pai_codigo),
            $rawProducts->map(fn ($product) => ['pro_codigo' => $product->pro_codigo])->all(),
        );

        $products = $rawProducts
            ->map(function ($product) use ($country, $availability) {
                $category = trim((string) ($product->categoria_nombre ?: $product->pro_oc_categoria ?: 'Catalogo'));
                $subcategory = trim((string) ($product->subcategoria_nombre ?: ''));
                $badge = trim((string) ($product->ppa_promo_nombre ?: ($product->ppa_es_popular ? 'Popular' : 'Disponible')));
                $description = trim((string) $product->pro_descripcion);
                $sku = trim((string) $product->pro_codigo);
                $availabilitySummary = $availability['availabilityBySku'][$sku] ?? null;

                if ($description === '') {
                    $description = $subcategory !== ''
                        ? "Categoria {$category} | {$subcategory}"
                        : "Categoria {$category}";
                }

                return [
                    'id' => (int) $product->pro_id,
                    'name' => trim((string) $product->pro_nombre),
                    'slug' => Str::slug((string) $product->pro_nombre).'-'.$product->pro_id,
                    'sku' => $sku,
                    'price' => (float) $product->ppa_precio,
                    'currency' => $this->currencyForCountry(strtolower((string) $country->pai_codigo)),
                    'badge' => $badge,
                    'category' => $category,
                    'brand' => trim((string) ($product->pro_marca ?: 'ST JACKS')),
                    'description' => $description,
                    'sizes' => trim((string) ($product->pro_tallas ?: '')),
                    'availableSizes' => $availabilitySummary['availableSizes'] ?? [],
                    'hasStock' => (bool) ($availabilitySummary['hasStock'] ?? false),
                    'stockTotal' => (int) ($availabilitySummary['totalQuantity'] ?? 0),
                    'imageUrl' => $product->pro_thumbs
                        ? self::LEGACY_HOST.'/images/p400/'.ltrim((string) $product->pro_thumbs, '/')
                        : null,
                ];
            })
            ->values()
            ->all();

        $categories = (clone $baseQuery)
            ->select([
                'c.cat_nombre',
                DB::raw('COUNT(*) as total'),
            ])
            ->whereNotNull('c.cat_nombre')
            ->groupBy('c.cat_nombre')
            ->orderBy('c.cat_nombre')
            ->limit(12)
            ->get()
            ->map(fn ($category) => [
                'label' => trim((string) $category->cat_nombre),
                'value' => trim((string) $category->cat_nombre),
                'count' => (int) $category->total,
            ])
            ->filter(fn (array $category) => $category['label'] !== '')
            ->values()
            ->all();

        return [
            'filters' => [
                'groups' => $this->groupFilters(),
                'categories' => $categories,
                'sorts' => $this->sortFilters(),
                'promotions' => $this->promotionFilters(),
                'active' => [
                    'group' => $activeGroup,
                    'category' => $activeCategory,
                    'sort' => $activeSort,
                    'promo' => $promoOnly,
                ],
            ],
            'products' => $products,
            'search' => [
                'query' => $trimmedQuery,
                'total' => count($products),
            ],
            'availability' => [
                'activeStoreCode' => $availability['activeStoreCode'] ?? null,
                'usedSource' => $availability['usedSource'] ?? null,
            ],
        ];
    }

    private function resolveCountry(string $countryCode): ?object
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->where('pai_codigo', strtoupper($countryCode))
            ->first();
    }

    private function currencyForCountry(string $countryCode): string
    {
        return match ($countryCode) {
            'gt' => 'GTQ',
            'cr' => 'CRC',
            'do' => 'DOP',
            'hn' => 'HNL',
            default => 'USD',
        };
    }

    private function applyGroupFilter($query, string $group): void
    {
        $categories = self::GROUP_MAPPINGS[$group] ?? null;

        if (! $categories) {
            return;
        }

        $query->whereIn('c.cat_nombre', $categories);
    }

    private function applyCategoryFilter($query, string $category): void
    {
        if ($category === '') {
            return;
        }

        $query->where('c.cat_nombre', $category);
    }

    private function applyPromoFilter($query, bool $promoOnly): void
    {
        if (! $promoOnly) {
            return;
        }

        $query->whereNotNull('pp.ppa_promo_nombre')
            ->where('pp.ppa_promo_nombre', '!=', '');
    }

    private function applySort($query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('p.pro_registro'),
            'price_asc' => $query->orderBy('pp.ppa_precio'),
            'price_desc' => $query->orderByDesc('pp.ppa_precio'),
            default => $query
                ->orderByDesc('pp.ppa_es_popular')
                ->orderByDesc('p.pro_registro'),
        };
    }

    private function groupFilters(): array
    {
        return [
            ['value' => 'girls', 'label' => 'Ninas'],
            ['value' => 'boys', 'label' => 'Ninos'],
            ['value' => 'toddlers', 'label' => 'Toddlers'],
            ['value' => 'babies', 'label' => 'Bebes'],
            ['value' => 'adults', 'label' => 'Adultos'],
            ['value' => 'teens', 'label' => 'Juvenil'],
            ['value' => 'accessories', 'label' => 'Accesorios'],
        ];
    }

    private function sortFilters(): array
    {
        return [
            ['value' => 'featured', 'label' => 'Destacados'],
            ['value' => 'newest', 'label' => 'Novedades'],
            ['value' => 'price_asc', 'label' => 'Precio menor'],
            ['value' => 'price_desc', 'label' => 'Precio mayor'],
        ];
    }

    private function promotionFilters(): array
    {
        return [
            ['value' => '1', 'label' => 'Solo rebajas'],
        ];
    }
}
