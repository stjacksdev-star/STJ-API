<?php

namespace App\Services;

use App\Support\StorefrontProductExclusions;
use App\Support\StorefrontBrandMap;
use App\Support\StorefrontImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StorefrontBrandService
{
    public function __construct(
        private readonly ProductListAvailabilityService $productListAvailabilityService,
        private readonly ?StorefrontProductPromotionPresenter $promotionPresenter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function show(string $countryCode, string $slug, array $filters = []): ?array
    {
        $brand = $this->findBrand($slug);

        if (! $brand || strtoupper((string) $this->value($brand, 'mar_estado')) !== 'ACTIVA') {
            return null;
        }

        if (strtolower((string) $this->value($brand, 'mar_slug')) === 'denim') {
            return null;
        }

        $country = $this->resolveCountry($countryCode);

        if (! $country) {
            return [
                ...$this->normalizeBrand($brand),
                'products' => [],
                'featured' => [],
                'newArrivals' => [],
                'filters' => $this->emptyFilters($filters),
                'pagination' => $this->pagination(1, 0, 12),
            ];
        }

        $query = trim((string) ($filters['q'] ?? ''));
        $group = trim((string) ($filters['group'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $sort = trim((string) ($filters['sort'] ?? 'featured'));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(6, min((int) ($filters['perPage'] ?? 12), 24));
        $slug = strtolower((string) $this->value($brand, 'mar_slug'));

        $baseQuery = $this->baseProductQuery((int) $country->pai_id);
        StorefrontBrandMap::applyProductBrandFilter($baseQuery, $slug);
        $brandOnlyQuery = clone $baseQuery;
        $this->applySearchFilter($baseQuery, $query);

        $groupsQuery = clone $baseQuery;
        $this->applyCategoryFilter($groupsQuery, $category);

        $categoriesQuery = clone $baseQuery;
        $this->applyGroupFilter($categoriesQuery, $group);

        $heroBlocksQuery = clone $brandOnlyQuery;

        $this->applyGroupFilter($baseQuery, $group);
        $this->applyCategoryFilter($baseQuery, $category);

        $total = (clone $baseQuery)->count();
        $this->applySort($baseQuery, $sort);

        $rawProducts = $baseQuery
            ->select($this->productSelects())
            ->forPage($page, $perPage)
            ->get();

        $products = $this->mapProducts($rawProducts, $country, $slug);
        $featured = $this->featuredProducts((int) $country->pai_id, $country, $slug);
        $newArrivals = $this->newArrivalProducts((int) $country->pai_id, $country, $slug);

        return [
            ...$this->normalizeBrand($brand),
            'products' => $products,
            'featured' => $featured,
            'newArrivals' => $newArrivals,
            'featureBlocks' => $this->featureBlocks($heroBlocksQuery, $slug),
            'filters' => [
                'active' => [
                    'q' => $query,
                    'group' => $group,
                    'category' => $category,
                    'sort' => $sort,
                ],
                'groups' => $this->groups($groupsQuery),
                'categories' => $this->categories($categoriesQuery),
                'sorts' => [
                    ['value' => 'featured', 'label' => 'Destacados'],
                    ['value' => 'newest', 'label' => 'Novedades'],
                    ['value' => 'price_asc', 'label' => 'Precio menor'],
                    ['value' => 'price_desc', 'label' => 'Precio mayor'],
                ],
            ],
            'pagination' => $this->pagination($page, $total, $perPage),
        ];
    }

    private function findBrand(string $slug): ?object
    {
        return DB::table('stj_marcas')
            ->where('mar_slug', strtolower(trim($slug)))
            ->first();
    }

    private function resolveCountry(string $countryCode): ?object
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->where('pai_codigo', strtoupper($countryCode))
            ->first();
    }

    private function baseProductQuery(int $countryId)
    {
        $query = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO');

        return StorefrontProductExclusions::apply($query, 'p');
    }

    private function applyGroupFilter($query, string $group): void
    {
        if ($group === '') {
            return;
        }

        $query->where('c.cat_nombre', $group);
    }

    private function applySearchFilter($query, string $queryText): void
    {
        if ($queryText === '') {
            return;
        }

        $query->where(function ($subQuery) use ($queryText) {
            $subQuery
                ->where('p.pro_nombre', 'like', "%{$queryText}%")
                ->orWhere('p.pro_codigo', 'like', "%{$queryText}%")
                ->orWhere('p.pro_tags', 'like', "%{$queryText}%")
                ->orWhere('sc.sca_nombre', 'like', "%{$queryText}%")
                ->orWhere('c.cat_nombre', 'like', "%{$queryText}%");
        });
    }

    private function applyCategoryFilter($query, string $category): void
    {
        if ($category === '') {
            return;
        }

        $query->where('sc.sca_nombre', $category);
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

    /**
     * @return array<int, string>
     */
    private function productSelects(): array
    {
        return [
            'p.pro_id',
            'p.pro_codigo',
            'p.pro_thumbs',
            'p.pro_nombre',
            'p.pro_descripcion',
            'p.pro_marca',
            'p.pro_categoria',
            'p.pro_sub_categoria',
            'p.pro_oc_categoria',
            'p.pro_tallas',
            'pp.ppa_precio',
            'pp.ppa_es_popular',
            'sc.sca_nombre as subcategoria_nombre',
            'c.cat_nombre as categoria_nombre',
            'c.cat_header',
            'c.cat_logo_app',
        ];
    }

    private function mapProducts($rawProducts, object $country, string $brandSlug): array
    {
        $availability = $this->productListAvailabilityService->summarize(
            strtolower((string) $country->pai_codigo),
            $rawProducts->map(fn ($product) => ['pro_codigo' => $product->pro_codigo])->all(),
        );
        $commercial = ($this->promotionPresenter ?? app(StorefrontProductPromotionPresenter::class))->resolve(
            $rawProducts,
            (int) $country->pai_id,
            (string) $country->pai_codigo,
        );

        return $rawProducts
            ->map(function ($product) use ($country, $availability, $brandSlug, $commercial) {
                $category = trim((string) ($product->categoria_nombre ?: $product->pro_oc_categoria ?: 'Catalogo'));
                $subcategory = trim((string) ($product->subcategoria_nombre ?: ''));
                $description = trim((string) $product->pro_descripcion);
                $sku = trim((string) $product->pro_codigo);
                $availabilitySummary = $availability['availabilityBySku'][$sku] ?? null;
                $resolved = $commercial->get((int) $product->pro_id);
                $promotion = $resolved['promotion'] ?? null;
                $regularPrice = round((float) $product->ppa_precio, 2);
                $finalPrice = round((float) ($resolved['finalTotal'] ?? $regularPrice), 2);
                $hasDiscount = $promotion !== null
                    && (int) round($finalPrice * 100) < (int) round($regularPrice * 100);

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
                    'price' => $finalPrice,
                    'previousPrice' => $hasDiscount ? $regularPrice : null,
                    'discountPercentage' => $promotion['discountPercentage'] ?? null,
                    'promoName' => $promotion['displayLabel'] ?? '',
                    'promotion' => $promotion,
                    'currency' => $this->currencyForCountry(strtolower((string) $country->pai_codigo)),
                    'badge' => isset($product->pme_ranking_ventas)
                        ? '#'.(int) $product->pme_ranking_ventas
                        : (string) ($promotion['displayLabel'] ?? ($product->ppa_es_popular ? 'Popular' : 'Disponible')),
                    'category' => $category,
                    'subcategory' => $subcategory,
                    'brand' => StorefrontBrandMap::canonical($brandSlug),
                    'description' => $description,
                    'sizes' => trim((string) ($product->pro_tallas ?: '')),
                    'availableSizes' => $availabilitySummary['availableSizes'] ?? [],
                    'hasStock' => (bool) ($availabilitySummary['hasStock'] ?? false),
                    'stockTotal' => (int) ($availabilitySummary['totalQuantity'] ?? 0),
                    'imageUrl' => StorefrontImageUrl::image((string) $product->pro_thumbs, 'p400'),
                ];
            })
            ->values()
            ->all();
    }

    private function featuredProducts(int $countryId, object $country, string $brandSlug): array
    {
        $period = app(ProductBestSellerCalculator::class)->period(30);
        $query = DB::table('stj_producto_metricas as metrics')
            ->join('stj_productos as p', 'p.pro_id', '=', 'metrics.pme_producto')
            ->join('stj_producto_pais as pp', function ($join) {
                $join->on('pp.ppa_producto', '=', 'p.pro_id')
                    ->on('pp.ppa_pais', '=', 'metrics.pme_pais');
            })
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('metrics.pme_pais', $countryId)
            ->where('metrics.pme_periodo', $period)
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO');
        StorefrontProductExclusions::apply($query, 'p');
        StorefrontBrandMap::applyProductBrandFilter($query, $brandSlug);

        $rawProducts = $query
            ->orderBy('metrics.pme_ranking_ventas')
            ->orderByDesc('metrics.pme_ventas_unidades')
            ->select([
                ...$this->productSelects(),
                'metrics.pme_ranking_ventas',
            ])
            ->limit(10)
            ->get();

        if ($rawProducts->isEmpty()) {
            return [];
        }

        return $this->mapProducts($rawProducts, $country, $brandSlug);
    }

    private function newArrivalProducts(int $countryId, object $country, string $brandSlug): array
    {
        $query = $this->baseProductQuery($countryId);
        StorefrontBrandMap::applyProductBrandFilter($query, $brandSlug);

        $rawProducts = $query
            ->orderByDesc('p.pro_registro')
            ->orderByDesc('p.pro_id')
            ->select($this->productSelects())
            ->limit(10)
            ->get();

        if ($rawProducts->isEmpty()) {
            return [];
        }

        return $this->mapProducts($rawProducts, $country, $brandSlug);
    }

    private function groups($query): array
    {
        return $query
            ->select([
                'c.cat_nombre',
                DB::raw('MIN(c.cat_orden) as orden'),
                DB::raw('COUNT(*) as total'),
            ])
            ->whereNotNull('c.cat_nombre')
            ->groupBy('c.cat_nombre')
            ->orderByRaw('MIN(c.cat_orden) IS NULL')
            ->orderByRaw('MIN(c.cat_orden)')
            ->orderBy('c.cat_nombre')
            ->get()
            ->map(fn ($group) => [
                'label' => trim((string) $group->cat_nombre),
                'value' => trim((string) $group->cat_nombre),
                'count' => (int) $group->total,
            ])
            ->filter(fn (array $group) => $group['label'] !== '')
            ->values()
            ->all();
    }

    private function categories($query): array
    {
        return $query
            ->select([
                'sc.sca_nombre',
                DB::raw('COUNT(*) as total'),
            ])
            ->whereNotNull('sc.sca_nombre')
            ->groupBy('sc.sca_nombre')
            ->orderBy('sc.sca_nombre')
            ->get()
            ->map(fn ($category) => [
                'label' => trim((string) $category->sca_nombre),
                'value' => trim((string) $category->sca_nombre),
                'count' => (int) $category->total,
            ])
            ->filter(fn (array $category) => $category['label'] !== '')
            ->values()
            ->all();
    }

    private function featureBlocks($query, string $brandSlug): array
    {
        if ($brandSlug !== 'jackco') {
            return [];
        }

        $copy = [
            'Teen Chicas' => 'Estilo con personalidad propia.',
            'Teen Chicos' => 'Actitud urbana para cada dia.',
        ];

        return $query
            ->whereIn('c.cat_nombre', array_keys($copy))
            ->select([
                'c.cat_nombre',
                'c.cat_logo_app',
                'c.cat_header',
                DB::raw('MIN(c.cat_orden) as orden'),
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy('c.cat_nombre', 'c.cat_logo_app', 'c.cat_header')
            ->orderByRaw('MIN(c.cat_orden) IS NULL')
            ->orderByRaw('MIN(c.cat_orden)')
            ->orderBy('c.cat_nombre')
            ->get()
            ->map(fn ($block) => [
                'title' => trim((string) $block->cat_nombre),
                'text' => $copy[trim((string) $block->cat_nombre)] ?? '',
                'button' => 'Ver coleccion',
                'group' => trim((string) $block->cat_nombre),
                'image' => $this->asset($block->cat_logo_app ?: $block->cat_header),
                'count' => (int) $block->total,
            ])
            ->filter(fn (array $block) => $block['title'] !== '' && $block['count'] > 0)
            ->values()
            ->all();
    }

    private function normalizeBrand(object $brand): array
    {
        $slug = strtolower((string) $this->value($brand, 'mar_slug'));

        return [
            'id' => (int) $this->value($brand, 'mar_id'),
            'nombre' => (string) $this->value($brand, 'mar_nombre'),
            'slug' => $slug,
            'codigo' => (string) $this->value($brand, 'mar_codigo'),
            'logo' => $this->asset($this->firstValue($brand, ['mar_logo', 'mar_logo_desktop'])),
            'mobileLogo' => $this->asset($this->firstValue($brand, ['mar_logo_mobile', 'mar_logo_circular', 'mar_logo_icono', 'mar_logo'])),
            'hero' => [
                'titulo' => (string) $this->value($brand, 'mar_titulo'),
                'descripcion' => (string) $this->value($brand, 'mar_descripcion'),
                'desktop' => $this->asset($this->value($brand, 'mar_imagen_desktop')),
                'mobile' => $this->asset($this->value($brand, 'mar_imagen_mobile')),
                'boton' => (string) ($this->value($brand, 'mar_boton') ?: 'Explorar'),
            ],
            'theme' => [
                'primary' => (string) ($this->value($brand, 'mar_color_primario') ?: '#009FE3'),
                'secondary' => (string) ($this->value($brand, 'mar_color_secundario') ?: '#FFFFFF'),
                'accent' => (string) ($this->value($brand, 'mar_color_acento') ?: '#FF2D6F'),
                'text' => (string) ($this->value($brand, 'mar_color_texto') ?: '#222222'),
                'background' => (string) ($this->value($brand, 'mar_color_fondo') ?: '#FFFFFF'),
                'filter' => [
                    'background' => (string) ($this->value($brand, 'mar_color_filtro_fondo') ?: '#FFFFFF'),
                    'title' => (string) ($this->value($brand, 'mar_color_filtro_titulo') ?: '#222222'),
                    'text' => (string) ($this->value($brand, 'mar_color_filtro_texto') ?: '#3F3F3F'),
                    'secondary' => (string) ($this->value($brand, 'mar_color_filtro_secundario') ?: '#606060'),
                    'border' => (string) ($this->value($brand, 'mar_color_filtro_borde') ?: 'rgba(15,23,42,0.08)'),
                    'activeBackground' => (string) ($this->value($brand, 'mar_color_filtro_activo_fondo') ?: '#FFFFFF'),
                    'activeText' => (string) ($this->value($brand, 'mar_color_filtro_activo_texto') ?: '#111111'),
                ],
            ],
        ];
    }

    private function firstValue(object $brand, array $columns): mixed
    {
        foreach ($columns as $column) {
            $value = $this->value($brand, $column);

            if (trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function value(object $brand, string $column): mixed
    {
        static $columns = null;
        $columns ??= Schema::getColumnListing('stj_marcas');

        return in_array($column, $columns, true) ? ($brand->{$column} ?? null) : null;
    }

    private function asset(mixed $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return StorefrontImageUrl::asset($path);
    }

    private function emptyFilters(array $filters): array
    {
        return [
            'active' => [
                'q' => trim((string) ($filters['q'] ?? '')),
                'group' => trim((string) ($filters['group'] ?? '')),
                'category' => trim((string) ($filters['category'] ?? '')),
                'sort' => trim((string) ($filters['sort'] ?? 'featured')),
            ],
            'groups' => [],
            'categories' => [],
            'sorts' => [],
        ];
    }

    private function pagination(int $page, int $total, int $perPage): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'page' => min($page, $lastPage),
            'perPage' => $perPage,
            'total' => $total,
            'lastPage' => $lastPage,
        ];
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
}
