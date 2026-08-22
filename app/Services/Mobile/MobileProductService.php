<?php

namespace App\Services\Mobile;

use App\Services\ProductListAvailabilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileProductService
{
    public function __construct(
        private readonly ProductListAvailabilityService $availability,
    ) {}

    public function filter(int $countryId, array $filters): array
    {
        $country = DB::table('stj_paises')
            ->where('pai_id', $countryId)
            ->first(['pai_id', 'pai_codigo']);

        if (! $country) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $storeCode = trim((string) $filters['tienda']);
        if (! DB::table('stj_tiendas')->where('tie_pais', $countryId)->where('tie_codigo', $storeCode)->exists()) {
            throw ValidationException::withMessages(['tienda' => 'La tienda no pertenece al pais seleccionado.']);
        }

        $category = DB::table('stj_categorias')
            ->where('cat_id', (int) $filters['categoria'])
            ->first(['cat_id', 'cat_si_sub_otras', 'cat_sub_otras', 'cat_marca']);

        if (! $category) {
            throw ValidationException::withMessages(['categoria' => 'Categoria no encontrada.']);
        }

        $query = DB::table('stj_productos as p')
            ->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->join('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->join('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('pp.ppa_precio', '>', 0.99);

        $this->applyCategory($query, $category, $filters);
        $this->applyPriceAndSizeFilters($query, $filters);
        $this->applySort($query, (string) ($filters['ordenamiento'] ?? 'Más recientes'));

        $products = $query->limit($countryId === 1 ? 150 : 100)->get([
            'p.pro_id', 'p.pro_codigo', 'p.pro_nombre', 'p.pro_descripcion', 'p.pro_marca',
            'p.pro_oc_marca', 'p.pro_categoria', 'p.pro_tallas', 'c.cat_nombre', 'sc.sca_nombre',
            'pp.ppa_precio', 'pp.ppa_descuento', 'pp.ppa_origen_descuento', 'pp.ppa_promo_nombre',
            'pp.ppa_promo_logo', 'pp.ppa_tipo_descuento', 'pp.ppa_precio_tienda',
        ]);

        $availability = $this->availability->summarize(
            strtolower((string) $country->pai_codigo),
            $products->map(fn (object $product) => ['pro_codigo' => $product->pro_codigo])->all(),
            $storeCode,
        );
        $bySku = $availability['availabilityBySku'] ?? [];

        return [
            'records' => $products
                ->filter(fn (object $product) => (bool) ($bySku[trim((string) $product->pro_codigo)]['hasStock'] ?? false))
                ->map(fn (object $product) => $this->legacyProduct($product, $bySku))
                ->values()
                ->all(),
            'existenciaTalla' => $availability['availabilityRows'] ?? [],
        ];
    }

    private function applyCategory(Builder $query, object $category, array $filters): void
    {
        if ((bool) $category->cat_si_sub_otras) {
            $subcategoryIds = collect(explode(',', (string) $category->cat_sub_otras))
                ->map(fn (string $id) => (int) trim($id))->filter()->unique()->all();
            $query->whereIn('p.pro_sub_categoria', $subcategoryIds)
                ->where('p.pro_marca', trim((string) ($category->cat_marca ?: 'ST JACKS')));
        } else {
            $query->where('p.pro_categoria', (int) $category->cat_id);
            $subcategory = trim((string) ($filters['scat'] ?? $filters['sub_id'] ?? ''));
            if ($subcategory !== '') {
                ctype_digit($subcategory)
                    ? $query->where('p.pro_sub_categoria', (int) $subcategory)
                    : $query->where('sc.sca_nombre', $subcategory);
            }
        }
    }

    private function applyPriceAndSizeFilters(Builder $query, array $filters): void
    {
        if (($filters['min'] ?? '') !== '' && ($filters['min'] ?? null) !== null) {
            $query->where('pp.ppa_precio', '>=', (float) $filters['min']);
        }
        if (($filters['max'] ?? '') !== '' && ($filters['max'] ?? null) !== null) {
            $query->where('pp.ppa_precio', '<=', (float) $filters['max']);
        }
        if (trim((string) ($filters['talla'] ?? '')) !== '') {
            $query->where('p.pro_tallas', 'like', '%'.trim((string) $filters['talla']).'%');
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match (Str::ascii($sort)) {
            'Alfabeticamente: A-Z' => $query->orderBy('p.pro_nombre'),
            'Alfabeticamente: Z-A' => $query->orderByDesc('p.pro_nombre'),
            'Precio: Menor a Mayor' => $query->orderBy('pp.ppa_precio'),
            'Precio: Mayor a Menor' => $query->orderByDesc('pp.ppa_precio'),
            'Mas antiguos' => $query->orderBy('p.pro_id'),
            default => $query->orderByDesc('p.pro_id'),
        };
    }

    private function legacyProduct(object $product, array $availabilityBySku): array
    {
        $price = (float) $product->ppa_precio;
        $discount = (float) ($product->ppa_descuento ?? 0);
        $discountedPrice = $discount > 0 ? $price * (1 - ($discount / 100)) : $price;
        if ((trim((string) $product->ppa_promo_logo) !== '' || $product->ppa_tipo_descuento === 'PRECIO_TODO')
            && (float) $product->ppa_precio_tienda > 0) {
            $discountedPrice = (float) $product->ppa_precio_tienda;
        }
        $sku = trim((string) $product->pro_codigo);

        return [
            'id' => $product->pro_id,
            'sku' => $sku,
            'marca' => $this->normalizeBrand($product),
            'nombre' => mb_convert_case(mb_strtolower((string) $product->pro_nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'precio' => number_format($price, 2, '.', ''),
            'descuento' => $discount,
            'origen' => (string) ($product->ppa_origen_descuento ?? ''),
            'sello' => '',
            'precioCD' => number_format($discountedPrice, 2, '.', ''),
            'descripcion' => str_replace('-', '<br/>-', (string) $product->pro_descripcion),
            'categoria' => $product->pro_categoria,
            'subCategoriaTxt' => (string) $product->sca_nombre,
            'categoriaTxt' => (string) $product->cat_nombre,
            'foto' => config('mobile.legacy_product_image_url').'/'.$sku.'.jpg?'.(string) $product->pro_nombre,
            'envioGratis' => 'NO',
            'Domicilio' => true,
            'Tienda' => true,
            'ppa_promo_nombre' => (string) ($product->ppa_promo_nombre ?? ''),
            'pro_tallas_list' => $product->pro_tallas ? explode(',', (string) $product->pro_tallas) : [],
            'availableSizes' => $availabilityBySku[$sku]['availableSizes'] ?? [],
            'hasStock' => true,
        ];
    }

    private function normalizeBrand(object $product): string
    {
        foreach ([$product->pro_oc_marca ?? '', $product->pro_marca ?? ''] as $brand) {
            $key = str_replace(['.', "'", ' '], '', strtoupper(trim((string) $brand)));
            if ($key === 'STJACKS') {
                return "ST. JACK'S";
            }
            if (in_array($key, ['BASIKOS', 'BASICS'], true)) {
                return 'BASIKOS';
            }
            if (in_array($key, ['JACK&CO', 'JACKCO'], true)) {
                return 'JACK & CO';
            }
        }

        return '';
    }
}
