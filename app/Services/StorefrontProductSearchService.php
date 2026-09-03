<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use App\Support\StorefrontProductExclusions;
use App\Support\StorefrontProductSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontProductSearchService
{
    public function suggestions(string $countryCode, string $text, int $limit = 8): array
    {
        $query = trim($text);
        $countryCode = strtoupper(trim($countryCode));
        $limit = max(1, min($limit, 10));

        if (mb_strlen($query) < 3) {
            return ['query' => $query, 'items' => [], 'total' => 0];
        }

        return Cache::remember(
            'storefront:search:'.sha1($countryCode.'|'.mb_strtolower($query).'|'.$limit),
            now()->addSeconds(60),
            fn () => $this->search($countryCode, $query, $limit),
        );
    }

    private function search(string $countryCode, string $text, int $limit): array
    {
        $country = DB::table('stj_paises')
            ->where('pai_codigo', $countryCode)
            ->first(['pai_id', 'pai_codigo']);

        if (! $country) {
            return ['query' => $text, 'items' => [], 'total' => 0];
        }

        $products = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $country->pai_id)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO');

        StorefrontProductExclusions::apply($products, 'p');
        StorefrontProductSearch::apply($products, $text);

        $items = $products
            ->select([
                'p.pro_id',
                'p.pro_codigo',
                'p.pro_nombre',
                'p.pro_thumbs',
                'p.pro_marca',
                'pp.ppa_es_popular',
                'c.cat_nombre as categoria_nombre',
                'sc.sca_nombre as subcategoria_nombre',
            ])
            ->orderByDesc('pp.ppa_es_popular')
            ->orderByDesc('p.pro_registro')
            ->limit($limit)
            ->get()
            ->map(fn (object $product) => [
                'id' => (int) $product->pro_id,
                'name' => trim((string) $product->pro_nombre),
                'slug' => Str::slug((string) $product->pro_nombre).'-'.$product->pro_id,
                'sku' => trim((string) $product->pro_codigo),
                'brand' => trim((string) ($product->pro_marca ?: 'ST JACKS')),
                'gender' => trim((string) ($product->categoria_nombre ?: '')),
                'category' => trim((string) ($product->subcategoria_nombre ?: '')),
                'imageUrl' => StorefrontImageUrl::image((string) $product->pro_thumbs, 'p100'),
            ])
            ->values()
            ->all();

        return ['query' => $text, 'items' => $items, 'total' => count($items)];
    }
}
