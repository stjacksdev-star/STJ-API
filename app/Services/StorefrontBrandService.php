<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StorefrontBrandService
{
    public function __construct(
        private readonly ProductListAvailabilityService $productListAvailabilityService,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
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
        $category = trim((string) ($filters['category'] ?? ''));
        $sort = trim((string) ($filters['sort'] ?? 'featured'));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(6, min((int) ($filters['perPage'] ?? 12), 24));

        $baseQuery = $this->baseProductQuery((int) $country->pai_id);
        $this->applyBrandFilter($baseQuery, $brand);
        $this->applySearchFilter($baseQuery, $query);

        $categoryQuery = clone $baseQuery;
        $this->applyCategoryFilter($baseQuery, $category);

        $total = (clone $baseQuery)->count();
        $this->applySort($baseQuery, $sort);

        $rawProducts = $baseQuery
            ->select($this->productSelects())
            ->forPage($page, $perPage)
            ->get();

        $products = $this->mapProducts($rawProducts, $country);
        $featured = collect($products)->take(6)->values()->all();
        $newArrivals = collect($products)->sortByDesc('id')->take(6)->values()->all();

        return [
            ...$this->normalizeBrand($brand),
            'products' => $products,
            'featured' => $featured,
            'newArrivals' => $newArrivals,
            'filters' => [
                'active' => [
                    'q' => $query,
                    'category' => $category,
                    'sort' => $sort,
                ],
                'categories' => $this->categories($categoryQuery),
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
        return DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO');
    }

    private function applyBrandFilter($query, object $brand): void
    {
        $terms = $this->brandTerms($brand);
        $slug = strtolower((string) $this->value($brand, 'mar_slug'));

        $query->where(function ($subQuery) use ($terms, $slug) {
            foreach ($terms as $term) {
                $subQuery->orWhere('p.pro_marca', 'like', "%{$term}%")
                    ->orWhere('p.pro_tags', 'like', "%{$term}%")
                    ->orWhere('p.pro_oc_categoria', 'like', "%{$term}%");
            }

            if ($slug === 'stjacks') {
                $subQuery->orWhereNull('p.pro_marca')
                    ->orWhere('p.pro_marca', '');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function brandTerms(object $brand): array
    {
        $name = trim((string) $this->value($brand, 'mar_nombre'));
        $code = trim((string) $this->value($brand, 'mar_codigo'));
        $slug = strtolower((string) $this->value($brand, 'mar_slug'));
        $extra = match ($slug) {
            'stjacks' => ['ST JACKS', "ST. JACK'S", 'STJACKS', 'STJ'],
            'basikos' => ['BASIKOS', 'BASICO', 'BAS'],
            'jackco' => ['JACK & CO', 'JACK&CO', 'JACKCO', 'JACK'],
            default => [],
        };

        return collect([$name, $code, $slug, ...$extra])
            ->map(fn (string $term) => trim($term))
            ->filter()
            ->unique()
            ->values()
            ->all();
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

        $query->where('c.cat_nombre', $category);
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
            'p.pro_oc_categoria',
            'p.pro_tallas',
            'pp.ppa_precio',
            'pp.ppa_promo_nombre',
            'pp.ppa_es_popular',
            'sc.sca_nombre as subcategoria_nombre',
            'c.cat_nombre as categoria_nombre',
        ];
    }

    private function mapProducts($rawProducts, object $country): array
    {
        $availability = $this->productListAvailabilityService->summarize(
            strtolower((string) $country->pai_codigo),
            $rawProducts->map(fn ($product) => ['pro_codigo' => $product->pro_codigo])->all(),
        );

        return $rawProducts
            ->map(function ($product) use ($country, $availability) {
                $category = trim((string) ($product->categoria_nombre ?: $product->pro_oc_categoria ?: 'Catalogo'));
                $subcategory = trim((string) ($product->subcategoria_nombre ?: ''));
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
                    'badge' => trim((string) ($product->ppa_promo_nombre ?: ($product->ppa_es_popular ? 'Popular' : 'Disponible'))),
                    'category' => $category,
                    'brand' => trim((string) ($product->pro_marca ?: 'ST JACKS')),
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

    private function categories($query): array
    {
        return $query
            ->select(['c.cat_nombre', DB::raw('COUNT(*) as total')])
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
                'category' => trim((string) ($filters['category'] ?? '')),
                'sort' => trim((string) ($filters['sort'] ?? 'featured')),
            ],
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
