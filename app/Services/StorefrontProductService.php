<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontProductService
{
    private const LEGACY_HOST = 'https://stjacks.com';

    public function forCountryAndSlug(string $countryCode, string $slug): ?array
    {
        $productId = $this->extractProductId($slug);
        $country = $this->resolveCountry($countryCode);

        if (! $country || ! $productId) {
            return null;
        }

        $product = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $country->pai_id)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO')
            ->where('p.pro_id', $productId)
            ->select([
                'p.pro_id',
                'p.pro_codigo',
                'p.pro_thumbs',
                'p.pro_nombre',
                'p.pro_descripcion',
                'p.pro_marca',
                'p.pro_oc_categoria',
                'p.pro_oc_coleccion',
                'p.pro_personaje',
                'p.pro_tags',
                'p.pro_tallas',
                'pp.ppa_precio',
                'pp.ppa_promo_nombre',
                'pp.ppa_es_popular',
                'sc.sca_nombre as subcategoria_nombre',
                'c.cat_nombre as categoria_nombre',
            ])
            ->first();

        if (! $product) {
            return null;
        }

        $normalized = $this->normalizeProduct($product, strtolower((string) $country->pai_codigo));
        $related = $this->relatedProducts($country->pai_id, (int) $product->pro_id, trim((string) ($product->categoria_nombre ?: '')));

        return [
            'product' => $normalized,
            'related' => $related,
        ];
    }

    private function normalizeProduct(object $product, string $countryCode): array
    {
        $category = trim((string) ($product->categoria_nombre ?: $product->pro_oc_categoria ?: 'Catalogo'));
        $subcategory = trim((string) ($product->subcategoria_nombre ?: ''));
        $description = trim((string) ($product->pro_descripcion ?: ''));

        if ($description === '') {
            $description = $subcategory !== ''
                ? "Categoria {$category} | {$subcategory}"
                : "Categoria {$category}";
        }

        $sizes = collect(explode(',', (string) ($product->pro_tallas ?: '')))
            ->map(fn ($size) => trim($size))
            ->filter()
            ->values()
            ->all();

        $gallery = $this->galleryForProduct((int) $product->pro_id, (string) ($product->pro_thumbs ?: ''));
        $imageUrl = $gallery[0]['imageUrl'] ?? null;

        return [
            'id' => (int) $product->pro_id,
            'name' => trim((string) $product->pro_nombre),
            'slug' => Str::slug((string) $product->pro_nombre).'-'.$product->pro_id,
            'sku' => trim((string) $product->pro_codigo),
            'price' => (float) $product->ppa_precio,
            'currency' => $this->currencyForCountry($countryCode),
            'badge' => trim((string) ($product->ppa_promo_nombre ?: ($product->ppa_es_popular ? 'Popular' : 'Disponible'))),
            'category' => $category,
            'subcategory' => $subcategory,
            'brand' => trim((string) ($product->pro_marca ?: 'ST JACKS')),
            'description' => $description,
            'sizes' => $sizes,
            'character' => trim((string) ($product->pro_personaje ?: '')),
            'collection' => trim((string) ($product->pro_oc_coleccion ?: '')),
            'tags' => trim((string) ($product->pro_tags ?: '')),
            'imageUrl' => $imageUrl,
            'gallery' => $gallery,
        ];
    }

    private function relatedProducts(int $countryId, int $productId, string $category): array
    {
        if ($category === '') {
            return [];
        }

        return DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO')
            ->where('p.pro_id', '!=', $productId)
            ->where('c.cat_nombre', $category)
            ->select([
                'p.pro_id',
                'p.pro_nombre',
                'p.pro_codigo',
                'p.pro_thumbs',
                'p.pro_marca',
                'p.pro_tallas',
                'pp.ppa_precio',
                'pp.ppa_promo_nombre',
                'pp.ppa_es_popular',
                'c.cat_nombre as categoria_nombre',
            ])
            ->orderByDesc('pp.ppa_es_popular')
            ->orderByDesc('p.pro_registro')
            ->limit(4)
            ->get()
            ->map(fn ($related) => [
                'id' => (int) $related->pro_id,
                'name' => trim((string) $related->pro_nombre),
                'slug' => Str::slug((string) $related->pro_nombre).'-'.$related->pro_id,
                'sku' => trim((string) $related->pro_codigo),
                'price' => (float) $related->ppa_precio,
                'badge' => trim((string) ($related->ppa_promo_nombre ?: ($related->ppa_es_popular ? 'Popular' : 'Disponible'))),
                'category' => trim((string) ($related->categoria_nombre ?: 'Catalogo')),
                'brand' => trim((string) ($related->pro_marca ?: 'ST JACKS')),
                'sizes' => trim((string) ($related->pro_tallas ?: '')),
                'imageUrl' => $related->pro_thumbs
                    ? self::LEGACY_HOST.'/images/p400/'.ltrim((string) $related->pro_thumbs, '/')
                    : null,
            ])
            ->values()
            ->all();
    }

    private function extractProductId(string $slug): ?int
    {
        if (! preg_match('/-(\d+)$/', $slug, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function resolveCountry(string $countryCode): ?object
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo'])
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

    private function galleryForProduct(int $productId, string $fallbackThumb = ''): array
    {
        $gallery = DB::table('stj_productos_fotos')
            ->where('pfo_producto', $productId)
            ->orderByDesc('pfo_portada')
            ->orderBy('pfo_orden')
            ->get(['pfo_url', 'pfo_orden', 'pfo_portada'])
            ->map(fn ($photo) => $this->formatGalleryImage((string) $photo->pfo_url, (int) $photo->pfo_orden, (bool) $photo->pfo_portada))
            ->filter()
            ->values()
            ->all();

        if ($gallery !== []) {
            return $gallery;
        }

        if (trim($fallbackThumb) === '') {
            return [];
        }

        $formatted = $this->formatGalleryImage($fallbackThumb, 1, true);

        return $formatted ? [$formatted] : [];
    }

    private function formatGalleryImage(string $filename, int $order, bool $isCover): ?array
    {
        $filename = ltrim(trim($filename), '/');

        if ($filename === '') {
            return null;
        }

        return [
            'imageUrl' => self::LEGACY_HOST.'/images/productos/'.$filename,
            'thumbUrl' => self::LEGACY_HOST.'/images/p400/'.$filename,
            'filename' => $filename,
            'order' => $order,
            'isCover' => $isCover,
        ];
    }
}
