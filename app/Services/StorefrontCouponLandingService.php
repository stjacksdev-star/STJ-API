<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontCouponLandingService
{
    public function __construct(private readonly ProductListAvailabilityService $availability) {}

    public function find(string $countryCode, int $headerId): ?array
    {
        $header = DB::table('stj_cupones_header as h')->join('stj_paises as p', 'p.pai_id', '=', 'h.che_pais')
            ->where('h.che_id', $headerId)->where('p.pai_codigo', strtoupper($countryCode))->where('h.che_estado', 'ACTIVO')
            ->whereIn('h.che_tipo_productos', ['PLA', 'GEN', 'COL'])
            ->where(fn ($q) => $q->whereNull('h.che_inicio')->orWhere('h.che_inicio', '<=', now()))
            ->where(fn ($q) => $q->whereNull('h.che_final')->orWhere('h.che_final', '>=', now()))
            ->first(['h.che_id', 'h.che_nombre', 'h.che_nombre_comercial', 'h.che_tipo', 'h.che_descuento', 'h.che_monto', 'h.che_final', 'h.che_tipo_productos', 'p.pai_id', 'p.pai_codigo']);
        if (! $header) return null;

        $rows = DB::table('stj_cupones_producto as cp')->join('stj_productos as product', 'product.pro_id', '=', 'cp.cpr_producto')
            ->join('stj_producto_pais as country_product', function ($join) use ($header) { $join->on('country_product.ppa_producto', '=', 'product.pro_id')->where('country_product.ppa_pais', (int) $header->pai_id); })
            ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'product.pro_categoria')
            ->leftJoin('stj_sub_categorias as subcategory', 'subcategory.sca_id', '=', 'product.pro_sub_categoria')
            ->where('cp.cpr_cupon', $headerId)->where('product.pro_estatus', 'ACTIVO')->where('country_product.ppa_estado', 'ACTIVO')
            ->orderBy('cp.cpr_id')->get(['product.pro_id', 'product.pro_codigo', 'product.pro_nombre', 'product.pro_descripcion', 'product.pro_marca', 'product.pro_thumbs', 'product.pro_tallas', 'country_product.ppa_precio', 'country_product.ppa_promo_nombre', 'country_product.ppa_es_popular', 'category.cat_nombre as categoria_nombre', 'subcategory.sca_nombre as subcategoria_nombre']);

        $stock = $this->availability->summarize(strtolower($header->pai_codigo), $rows->map(fn ($p) => ['pro_codigo' => $p->pro_codigo])->all());
        $products = $rows->map(function ($product) use ($header, $stock) {
            $sku = trim((string) $product->pro_codigo); $available = $stock['availabilityBySku'][$sku] ?? null;
            $category = trim((string) ($product->categoria_nombre ?: 'Cupón')); $subcategory = trim((string) ($product->subcategoria_nombre ?: ''));
            return ['id' => (int) $product->pro_id, 'name' => trim((string) $product->pro_nombre), 'slug' => Str::slug((string) $product->pro_nombre).'-'.$product->pro_id, 'sku' => $sku,
                'price' => (float) $product->ppa_precio, 'currency' => $this->currency(strtolower($header->pai_codigo)), 'badge' => trim((string) ($product->ppa_promo_nombre ?: ($product->ppa_es_popular ? 'Popular' : 'Disponible'))),
                'category' => $category, 'brand' => trim((string) ($product->pro_marca ?: 'ST JACKS')), 'description' => trim((string) $product->pro_descripcion) ?: ($subcategory ? "Categoría {$category} | {$subcategory}" : "Categoría {$category}"),
                'sizes' => trim((string) ($product->pro_tallas ?: '')), 'availableSizes' => $available['availableSizes'] ?? [], 'hasStock' => (bool) ($available['hasStock'] ?? false), 'stockTotal' => (int) ($available['totalQuantity'] ?? 0), 'imageUrl' => StorefrontImageUrl::image((string) $product->pro_thumbs, 'p400')];
        })->values()->all();

        return ['coupon' => ['headerId' => (int) $header->che_id, 'name' => trim((string) ($header->che_nombre_comercial ?: $header->che_nombre)), 'slug' => Str::slug((string) ($header->che_nombre_comercial ?: $header->che_nombre)),
            'benefit' => match ($header->che_tipo) { 'DESCUENTO' => $this->number($header->che_descuento).' % de descuento', 'PRECIO' => 'Precio especial de '.$this->currency(strtolower($header->pai_codigo)).' '.$this->number($header->che_monto), default => 'Envío gratis' },
            'endsAt' => $header->che_final, 'productScope' => $header->che_tipo_productos], 'products' => $products, 'total' => count($products)];
    }

    private function currency(string $country): string { return match ($country) { 'gt' => 'GTQ', 'cr' => 'CRC', 'hn' => 'HNL', default => 'USD' }; }
    private function number(mixed $value): string { return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'); }
}
