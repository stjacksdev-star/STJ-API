<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use App\Support\StorefrontProductExclusions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontCollectionService
{
    public function __construct(
        private readonly ProductListAvailabilityService $productListAvailabilityService,
        private readonly ?StorefrontProductPromotionPresenter $promotionPresenter = null,
    ) {}

    public function find(string $countryCode, int $collectionId, ?string $storeCode = null): ?array
    {
        $collection = DB::table('stj_coleccion as collection')
            ->join('stj_paises as country', 'country.pai_id', '=', 'collection.col_pais')
            ->select([
                'collection.col_id',
                'collection.col_nombre',
                'collection.col_titulo',
                'collection.col_header',
                'collection.col_posicion_movil',
                'collection.col_codigos',
                'country.pai_id',
                'country.pai_codigo',
            ])
            ->where('collection.col_id', $collectionId)
            ->where('country.pai_codigo', strtoupper($countryCode))
            ->first();

        if (! $collection) {
            return null;
        }

        $codes = collect(explode(',', (string) $collection->col_codigos))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        $productQuery = DB::table('stj_productos as product')
                ->join('stj_producto_pais as country_product', function ($join) use ($collection) {
                    $join
                        ->on('country_product.ppa_producto', '=', 'product.pro_id')
                        ->where('country_product.ppa_pais', '=', (int) $collection->pai_id);
                })
                ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'product.pro_categoria')
                ->leftJoin('stj_sub_categorias as subcategory', 'subcategory.sca_id', '=', 'product.pro_sub_categoria')
                ->whereIn('product.pro_codigo', $codes->all())
                ->where('product.pro_estatus', 'ACTIVO')
                ->where('country_product.ppa_estado', 'ACTIVO');
        StorefrontProductExclusions::apply($productQuery);

        $rows = $codes->isEmpty()
            ? collect()
            : $productQuery
                ->select([
                    'product.pro_id',
                    'product.pro_codigo',
                    'product.pro_nombre',
                    'product.pro_descripcion',
                    'product.pro_marca',
                    'product.pro_thumbs',
                    'product.pro_tallas',
                    'country_product.ppa_precio',
                    'country_product.ppa_es_popular',
                    'category.cat_nombre as categoria_nombre',
                    'subcategory.sca_nombre as subcategoria_nombre',
                ])
                ->get()
                ->sortBy(fn ($product) => $codes->search(trim((string) $product->pro_codigo)))
                ->values();

        $availability = $this->productListAvailabilityService->summarize(
            strtolower((string) $collection->pai_codigo),
            $rows->map(fn ($product) => ['pro_codigo' => $product->pro_codigo])->all(),
            $storeCode,
        );
        $commercial = ($this->promotionPresenter ?? app(StorefrontProductPromotionPresenter::class))->resolve(
            $rows,
            (int) $collection->pai_id,
            (string) $collection->pai_codigo,
        );

        $products = $rows
            ->map(function ($product) use ($collection, $availability, $commercial) {
                $sku = trim((string) $product->pro_codigo);
                $stock = $availability['availabilityBySku'][$sku] ?? null;
                $category = trim((string) ($product->categoria_nombre ?: 'Coleccion'));
                $subcategory = trim((string) ($product->subcategoria_nombre ?: ''));
                $description = trim((string) $product->pro_descripcion);
                $resolved = $commercial->get((int) $product->pro_id);
                $promotion = $resolved['promotion'] ?? null;
                $regularPrice = (float) $product->ppa_precio;
                $finalPrice = (float) ($resolved['finalTotal'] ?? $regularPrice);

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
                    'previousPrice' => $finalPrice < $regularPrice ? $regularPrice : null,
                    'discountPercentage' => $promotion['discountPercentage'] ?? null,
                    'promoName' => $promotion['displayLabel'] ?? '',
                    'promotion' => $promotion,
                    'currency' => $this->currencyForCountry(strtolower((string) $collection->pai_codigo)),
                    'badge' => (string) ($promotion['displayLabel'] ?? ($product->ppa_es_popular ? 'Popular' : 'Disponible')),
                    'category' => $category,
                    'brand' => trim((string) ($product->pro_marca ?: 'ST JACKS')),
                    'description' => $description,
                    'sizes' => trim((string) ($product->pro_tallas ?: '')),
                    'availableSizes' => $stock['availableSizes'] ?? [],
                    'hasStock' => (bool) ($stock['hasStock'] ?? false),
                    'stockTotal' => (int) ($stock['totalQuantity'] ?? 0),
                    'imageUrl' => StorefrontImageUrl::image((string) $product->pro_thumbs, 'p400'),
                ];
            })
            ->values()
            ->all();

        return [
            'collection' => [
                'id' => (int) $collection->col_id,
                'name' => trim((string) $collection->col_nombre),
                'title' => trim((string) ($collection->col_titulo ?: $collection->col_nombre)),
                'slug' => Str::slug((string) $collection->col_nombre),
                'headerImage' => StorefrontImageUrl::asset((string) $collection->col_header),
                'mobilePosition' => $collection->col_posicion_movil ?: 'center',
                'countryCode' => strtolower((string) $collection->pai_codigo),
            ],
            'products' => $products,
            'total' => count($products),
            'availability' => [
                'activeStoreCode' => $availability['activeStoreCode'] ?? null,
                'usedSource' => $availability['usedSource'] ?? null,
            ],
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
