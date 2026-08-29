<?php

namespace App\Services\Mobile;

use App\Services\ProductDetailAvailabilityService;
use App\Services\ProductListAvailabilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileProductService
{
    private const RELATED_GENDERS = [
        'TEEN CHICOS' => ['TEEN CHICOS', 'CABALLEROS JUVENIL', 'CABALLERO', 'CABALLERO JUVENI', 'CABALLEROS'],
        'CABALLEROS JUVENIL' => ['TEEN CHICOS', 'CABALLEROS JUVENIL', 'CABALLERO', 'CABALLERO JUVENI', 'CABALLEROS'],
        'CABALLERO' => ['TEEN CHICOS', 'CABALLEROS JUVENIL', 'CABALLERO', 'CABALLERO JUVENI', 'CABALLEROS'],
        'CABALLERO JUVENI' => ['TEEN CHICOS', 'CABALLEROS JUVENIL', 'CABALLERO', 'CABALLERO JUVENI', 'CABALLEROS'],
        'CABALLEROS' => ['TEEN CHICOS', 'CABALLEROS JUVENIL', 'CABALLERO', 'CABALLERO JUVENI', 'CABALLEROS'],
        'TEEN CHICAS' => ['TEEN CHICAS', 'DAMAS JUVENIL', 'DAMAS'],
        'DAMAS JUVENIL' => ['TEEN CHICAS', 'DAMAS JUVENIL', 'DAMAS'],
        'DAMAS' => ['TEEN CHICAS', 'DAMAS JUVENIL', 'DAMAS'],
        'BEBOS' => ['BEBOS', 'NIÑOS', 'TODDLERS NIÑOS', 'TODDLERNIÑOS', 'TODDLER NIÑOS'],
        'NIÑOS' => ['BEBOS', 'NIÑOS', 'TODDLERS NIÑOS', 'TODDLERNIÑOS', 'TODDLER NIÑOS'],
        'TODDLERS NIÑOS' => ['BEBOS', 'NIÑOS', 'TODDLERS NIÑOS', 'TODDLERNIÑOS', 'TODDLER NIÑOS'],
        'TODDLERNIÑOS' => ['BEBOS', 'NIÑOS', 'TODDLERS NIÑOS', 'TODDLERNIÑOS', 'TODDLER NIÑOS'],
        'TODDLER NIÑOS' => ['BEBOS', 'NIÑOS', 'TODDLERS NIÑOS', 'TODDLERNIÑOS', 'TODDLER NIÑOS'],
        'BEBAS' => ['BEBAS', 'NIÑAS', 'TODDLERS NIÑAS', 'TODDLER NIÑAS'],
        'NIÑAS' => ['BEBAS', 'NIÑAS', 'TODDLERS NIÑAS', 'TODDLER NIÑAS'],
        'TODDLERS NIÑAS' => ['BEBAS', 'NIÑAS', 'TODDLERS NIÑAS', 'TODDLER NIÑAS'],
        'TODDLER NIÑAS' => ['BEBAS', 'NIÑAS', 'TODDLERS NIÑAS', 'TODDLER NIÑAS'],
    ];

    public function __construct(
        private readonly ProductListAvailabilityService $availability,
        private readonly ProductDetailAvailabilityService $detailAvailability,
    ) {}

    public function forCategory(int $countryId, int $categoryId, string $storeCode): array
    {
        [$country, $category, $storeCode] = $this->validatedContext(
            $countryId,
            $categoryId,
            $storeCode,
            'categoryId',
            'codigoTienda',
        );

        $query = $this->productQuery($countryId);
        $this->applyCategory($query, $category, []);
        $query->orderByDesc('pp.ppa_promo_logo')
            ->orderByDesc('pp.ppa_tipo_descuento')
            ->orderByDesc('p.pro_id')
            ->orderByDesc('p.pro_nombre');

        $products = $this->getProducts($query);
        $availability = $this->summarize($country, $products, $storeCode);
        $bySku = $availability['availabilityBySku'] ?? [];

        return $products
            ->filter(fn (object $product) => (bool) ($bySku[trim((string) $product->pro_codigo)]['hasStock'] ?? false))
            ->map(fn (object $product) => $this->legacyProduct($product, $bySku, true))
            ->values()
            ->all();
    }

    public function detail(int $countryId, int $productId, string $storeCode): array
    {
        if (! DB::table('stj_paises')->where('pai_id', $countryId)->exists()) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $storeCode = trim($storeCode);
        if (! DB::table('stj_tiendas')->where('tie_pais', $countryId)->where('tie_codigo', $storeCode)->exists()) {
            throw ValidationException::withMessages(['codigoTienda' => 'La tienda no pertenece al pais seleccionado.']);
        }

        $product = DB::table('stj_productos as p')
            ->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->join('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->join('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('p.pro_id', $productId)
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->first([
                'p.pro_id', 'p.pro_nombre', 'p.pro_descripcion', 'p.pro_categoria',
                'p.pro_sub_categoria', 'c.cat_nombre', 'sc.sca_nombre', 'pp.ppa_precio',
            ]);

        if (! $product) {
            throw ValidationException::withMessages(['product' => 'Producto no encontrado para el pais seleccionado.']);
        }

        return [
            'id' => $product->pro_id,
            'nombre' => mb_convert_case(mb_strtolower((string) $product->pro_nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'preciov2' => number_format((float) $product->ppa_precio, 2),
            'descripcion' => str_replace('-', '<br/>-', (string) $product->pro_descripcion),
            'categoria' => (int) $product->pro_categoria,
            'subCategoria' => (int) $product->pro_sub_categoria,
            'categoriaTxt' => (string) $product->cat_nombre,
            'subCategoriaTxt' => (string) $product->sca_nombre,
            'Domicilio' => true,
            'Tienda' => true,
        ];
    }

    public function sizes(int $countryId, string $sku, string $storeCode): array
    {
        $country = DB::table('stj_paises')->where('pai_id', $countryId)->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $storeCode = trim($storeCode);
        if (! DB::table('stj_tiendas')->where('tie_pais', $countryId)->where('tie_codigo', $storeCode)->exists()) {
            throw ValidationException::withMessages(['codigoTienda' => 'La tienda no pertenece al pais seleccionado.']);
        }

        $product = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_codigo', trim($sku))
            ->first(['p.pro_id']);
        if (! $product) {
            throw ValidationException::withMessages(['sku' => 'Producto no encontrado para el pais seleccionado.']);
        }

        $availability = $this->detailAvailability->forCountryAndSlug(
            strtolower((string) $country->pai_codigo),
            'mobile-product-'.(int) $product->pro_id,
            $storeCode,
            'product_detail',
        );
        if (! $availability) {
            throw ValidationException::withMessages(['sku' => 'No se pudo resolver la disponibilidad del producto.']);
        }

        $sizes = collect($availability['sizes'] ?? []);

        return [
            'records' => $sizes
                ->filter(fn (array $size) => (bool) ($size['availableInActiveStore'] ?? false))
                ->map(fn (array $size) => ['talla' => (string) $size['size']])
                ->values()
                ->all(),
            'records2' => $sizes
                ->map(fn (array $size) => ['talla' => (string) $size['size']])
                ->values()
                ->all(),
            'disp' => $this->availabilityTable($availability),
        ];
    }

    public function suggestions(int $countryId, int $productId, string $storeCode): array
    {
        $country = DB::table('stj_paises')->where('pai_id', $countryId)->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $storeCode = trim($storeCode);
        if (! DB::table('stj_tiendas')->where('tie_pais', $countryId)->where('tie_codigo', $storeCode)->exists()) {
            throw ValidationException::withMessages(['codigoTienda' => 'La tienda no pertenece al pais seleccionado.']);
        }

        $seed = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_id', $productId)
            ->first(['p.pro_id', 'p.pro_oc_personaje', 'p.pro_oc_genero']);
        if (! $seed) {
            throw ValidationException::withMessages(['product' => 'Producto no encontrado para el pais seleccionado.']);
        }

        $query = $this->productQuery($countryId)
            ->where('p.pro_id', '<>', $productId)
            ->where('p.pro_oc_personaje', (string) $seed->pro_oc_personaje);
        $genders = self::RELATED_GENDERS[strtoupper(trim((string) $seed->pro_oc_genero))] ?? [];
        if ($genders !== []) {
            $query->whereIn('p.pro_oc_genero', $genders);
        }

        $products = $query->inRandomOrder()->limit(30)->get([
            'p.pro_id', 'p.pro_codigo', 'p.pro_nombre', 'p.pro_descripcion', 'p.pro_marca', 'p.pro_oc_marca',
            'p.pro_categoria', 'p.pro_sub_categoria', 'p.pro_tallas', 'c.cat_nombre', 'sc.sca_nombre',
            'pp.ppa_precio', 'pp.ppa_descuento', 'pp.ppa_origen_descuento', 'pp.ppa_promo_nombre',
            'pp.ppa_promo_logo', 'pp.ppa_tipo_descuento', 'pp.ppa_precio_tienda',
        ]);
        $availability = $this->summarize($country, $products, $storeCode);
        $bySku = $availability['availabilityBySku'] ?? [];

        $popular = $products
            ->filter(fn (object $product) => (bool) ($bySku[trim((string) $product->pro_codigo)]['hasStock'] ?? false))
            ->take(10)
            ->map(function (object $product) use ($bySku): array {
                $sku = trim((string) $product->pro_codigo);

                return [
                    'pro_id' => $product->pro_id,
                    'pro_codigo' => $sku,
                    'pro_nombre' => $product->pro_nombre,
                    'pro_descripcion' => $product->pro_descripcion,
                    'pro_marca' => $this->normalizeBrand($product),
                    'pro_oc_marca' => $this->normalizeBrand($product),
                    'pro_categoria' => $product->pro_categoria,
                    'pro_sub_categoria' => $product->pro_sub_categoria,
                    'pro_tallas' => $product->pro_tallas,
                    'cat_nombre' => $product->cat_nombre,
                    'sca_nombre' => $product->sca_nombre,
                    'ppa_precio' => $product->ppa_precio,
                    'ppa_descuento' => $product->ppa_descuento,
                    'ppa_origen_descuento' => $product->ppa_origen_descuento,
                    'ppa_promo_nombre' => $product->ppa_promo_nombre,
                    'ppa_promo_logo' => $product->ppa_promo_logo,
                    'ppa_tipo_descuento' => $product->ppa_tipo_descuento,
                    'ppa_precio_tienda' => $product->ppa_precio_tienda,
                    'availableSizes' => $bySku[$sku]['availableSizes'] ?? [],
                    'hasStock' => true,
                ];
            })
            ->values()
            ->all();

        return ['populares' => $popular, 'resultado' => true];
    }

    public function photos(int $countryId, int $productId): array
    {
        if (! DB::table('stj_paises')->where('pai_id', $countryId)->exists()) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $product = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_id', $productId)
            ->first(['p.pro_id', 'p.pro_nombre']);
        if (! $product) {
            throw ValidationException::withMessages(['product' => 'Producto no encontrado para el pais seleccionado.']);
        }

        $imageUrl = (string) config('mobile.legacy_product_image_url');

        return DB::table('stj_productos_fotos')
            ->where('pfo_producto', $productId)
            ->orderBy('pfo_orden')
            ->get(['pfo_url'])
            ->map(fn (object $photo): array => [
                'foto' => $imageUrl.'/'.ltrim((string) $photo->pfo_url, '/').'?'.(string) $product->pro_nombre,
            ])
            ->values()
            ->all();
    }

    public function favoriteStatus(int $countryId, int $productId, int $userId): array
    {
        if (! DB::table('stj_paises')->where('pai_id', $countryId)->exists()) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $productExists = DB::table('stj_producto_pais')
            ->where('ppa_pais', $countryId)
            ->where('ppa_producto', $productId)
            ->where('ppa_estado', 'ACTIVO')
            ->exists();
        if (! $productExists) {
            throw ValidationException::withMessages(['product' => 'Producto no encontrado para el pais seleccionado.']);
        }

        $isNewFavorite = Schema::hasTable('stj_favoritos') && DB::table('stj_favoritos')
            ->where('fav_pais', $countryId)
            ->where('fav_usuario', $userId)
            ->where('fav_producto', $productId)
            ->exists();
        $legacyState = Schema::hasTable('stj_hearts')
            ? DB::table('stj_hearts')
                ->where('hea_pais', $countryId)
                ->where('hea_usuario', $userId)
                ->where('hea_producto', $productId)
                ->orderByDesc('hea_id')
                ->value('hea_estado')
            : null;
        $favorite = $isNewFavorite || strtoupper(trim((string) $legacyState)) === 'ACTIVO';

        return [
            'resultado' => true,
            'favorito' => $favorite,
            'estado' => $favorite ? 'ACTIVO' : 'INACTIVO',
        ];
    }

    public function setFavorite(
        int $countryId,
        int $productId,
        int $userId,
        string $state,
        string $platform,
        array $legacyData = [],
    ): array {
        if (! DB::table('stj_paises')->where('pai_id', $countryId)->exists()) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $product = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_id', $productId)
            ->first(['p.pro_id', 'p.pro_categoria', 'p.pro_sub_categoria']);
        if (! $product) {
            throw ValidationException::withMessages(['producto' => 'Producto no encontrado para el pais seleccionado.']);
        }

        $origin = strtoupper(trim($platform));
        if (! in_array($origin, ['IOS', 'ANDROID'], true)) {
            $origin = 'MOBILE';
        }

        DB::transaction(function () use ($countryId, $productId, $userId, $state, $origin, $legacyData, $product): void {
            if (Schema::hasTable('stj_favoritos')) {
                $key = [
                    'fav_pais' => $countryId,
                    'fav_usuario' => $userId,
                    'fav_producto' => $productId,
                ];
                if ($state === 'ACTIVO') {
                    $favorite = DB::table('stj_favoritos')->where($key);
                    if ($favorite->exists()) {
                        $favorite->update(['fav_origen' => $origin, 'fav_updated_at' => now()]);
                    } else {
                        DB::table('stj_favoritos')->insert($key + [
                            'fav_visitante' => null,
                            'fav_origen' => $origin,
                            'fav_created_at' => now(),
                            'fav_updated_at' => now(),
                        ]);
                    }
                } else {
                    DB::table('stj_favoritos')->where($key)->delete();
                }
            }

            if (Schema::hasTable('stj_hearts')) {
                $key = [
                    'hea_pais' => $countryId,
                    'hea_usuario' => $userId,
                    'hea_producto' => $productId,
                ];
                $values = ['hea_estado' => $state];
                $optional = [
                    'hea_categoria' => $legacyData['categoria'] ?? $product->pro_categoria,
                    'hea_sub_categoria' => $legacyData['subCategoria'] ?? $product->pro_sub_categoria,
                    'hea_id_sesion' => $legacyData['idSesion'] ?? null,
                    'hea_sesion' => $legacyData['sesion'] ?? null,
                ];
                foreach ($optional as $column => $value) {
                    if (Schema::hasColumn('stj_hearts', $column)) {
                        $values[$column] = $value;
                    }
                }

                if ($state === 'ACTIVO') {
                    DB::table('stj_hearts')->updateOrInsert($key, $values);
                } else {
                    DB::table('stj_hearts')->where($key)->update(['hea_estado' => 'INACTIVO']);
                }
            }
        });

        return [
            'resultado' => true,
            'mensaje' => 'Favorito actualizado.',
            'estado' => $state,
            'favorito' => $state === 'ACTIVO',
        ];
    }

    public function favorites(int $countryId, int $userId, string $storeCode): array
    {
        $country = DB::table('stj_paises')->where('pai_id', $countryId)->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $storeCode = trim($storeCode);
        if (! DB::table('stj_tiendas')->where('tie_pais', $countryId)->where('tie_codigo', $storeCode)->exists()) {
            throw ValidationException::withMessages(['codigoTienda' => 'La tienda no pertenece al pais seleccionado.']);
        }

        $productIds = collect();
        if (Schema::hasTable('stj_favoritos')) {
            $productIds = DB::table('stj_favoritos')
                ->where('fav_pais', $countryId)
                ->where('fav_usuario', $userId)
                ->orderByDesc('fav_updated_at')
                ->orderByDesc('fav_id')
                ->pluck('fav_producto');
        }
        if (Schema::hasTable('stj_hearts')) {
            $legacyIds = DB::table('stj_hearts')
                ->where('hea_pais', $countryId)
                ->where('hea_usuario', $userId)
                ->where('hea_estado', 'ACTIVO')
                ->orderByDesc('hea_id')
                ->pluck('hea_producto');
            $productIds = $productIds->concat($legacyIds);
        }
        $productIds = $productIds->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($productIds->isEmpty()) {
            return [];
        }

        $productsById = $this->getProducts(
            $this->productQuery($countryId)->whereIn('p.pro_id', $productIds->all())
        )->keyBy(fn (object $product) => (int) $product->pro_id);
        $products = $productIds->map(fn (int $id) => $productsById->get($id))->filter()->values();
        $availability = $this->summarize($country, $products, $storeCode);
        $bySku = $availability['availabilityBySku'] ?? [];

        return $products->map(function (object $product) use ($bySku): array {
            $item = $this->legacyProduct($product, $bySku);
            $item['favorito'] = true;

            return $item;
        })->all();
    }

    private function availabilityTable(array $availability): string
    {
        $sizes = collect($availability['sizes'] ?? []);
        if ($sizes->isEmpty()) {
            return '';
        }

        $activeStore = $availability['activeStore'] ?? null;
        $stores = collect();
        if (is_array($activeStore) && trim((string) ($activeStore['code'] ?? '')) !== '') {
            $stores->put((string) $activeStore['code'], [
                'name' => (string) ($activeStore['name'] ?? $activeStore['code']),
                'active' => true,
                'quantities' => [],
            ]);
        }

        foreach ($sizes as $size) {
            $sizeName = (string) ($size['size'] ?? '');
            if ($activeStore) {
                $row = $stores->get((string) $activeStore['code']);
                $row['quantities'][$sizeName] = (int) ($size['quantityInActiveStore'] ?? 0);
                $stores->put((string) $activeStore['code'], $row);
            }
            foreach ($size['alternativeStores'] ?? [] as $alternative) {
                $code = (string) ($alternative['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $row = $stores->get($code, [
                    'name' => (string) ($alternative['name'] ?? $code),
                    'active' => false,
                    'quantities' => [],
                ]);
                $row['quantities'][$sizeName] = (int) ($alternative['quantity'] ?? 0);
                $stores->put($code, $row);
            }
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div class="tabs"><div class="tab"><div class="content"><table class="tbDisp" style="width:90%;margin:1em auto 2em;font-size:0.9em;"><thead><tr><td>Tienda</td>';
        foreach ($sizes as $size) {
            $html .= '<td>'.$escape((string) $size['size']).'</td>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($stores as $store) {
            $html .= '<tr'.($store['active'] ? ' style="background-color:yellow;"' : '').'><td>'.$escape($store['name']).'</td>';
            foreach ($sizes as $size) {
                $quantity = max(0, (int) ($store['quantities'][(string) $size['size']] ?? 0));
                $html .= '<td>'.($quantity > 4 ? '4+' : $quantity).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table></div></div></div>';
    }

    public function filter(int $countryId, array $filters): array
    {
        [$country, $category, $storeCode] = $this->validatedContext(
            $countryId,
            (int) $filters['categoria'],
            (string) $filters['tienda'],
        );

        $query = $this->productQuery($countryId)->where('pp.ppa_precio', '>', 0.99);

        $this->applyCategory($query, $category, $filters);
        $this->applyPriceAndSizeFilters($query, $filters);
        $this->applySort($query, (string) ($filters['ordenamiento'] ?? 'Más recientes'));

        $products = $this->getProducts($query, $countryId === 1 ? 150 : 100);

        $availability = $this->summarize($country, $products, $storeCode);
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

    public function filterJackCo(int $countryId, array $filters): array
    {
        [$country, $category, $storeCode] = $this->validatedContext(
            $countryId,
            (int) $filters['categoria'],
            (string) $filters['codigoTienda'],
            'categoria',
            'codigoTienda',
        );

        $query = $this->productQuery($countryId)
            ->where('pp.ppa_precio', '>', 0.99)
            ->where('p.pro_marca', 'JACK & CO');
        $this->applyCategoryScope($query, $category, $filters);
        $this->applyPriceAndSizeFilters($query, $filters);
        $this->applySort($query, (string) ($filters['ordenamiento'] ?? 'Más recientes'));

        $products = $this->getProducts($query);
        $availability = $this->summarize($country, $products, $storeCode);
        $bySku = $availability['availabilityBySku'] ?? [];

        return $products
            ->filter(fn (object $product) => (bool) ($bySku[trim((string) $product->pro_codigo)]['hasStock'] ?? false))
            ->map(function (object $product) use ($bySku): array {
                $item = $this->legacyProduct($product, $bySku);
                $item['sello'] = 'https://stjacks.com/img/v2/icons/Icon%20awesome-tag.svg';

                return $item;
            })
            ->values()
            ->all();
    }

    public function filterBasikos(int $countryId, array $filters): array
    {
        [$country, $category, $storeCode] = $this->validatedContext(
            $countryId,
            (int) $filters['categoria'],
            (string) $filters['codigoTienda'],
            'categoria',
            'codigoTienda',
        );

        $query = $this->productQuery($countryId)
            ->where('pp.ppa_precio', '>=', 0.99)
            ->whereIn('p.pro_marca', ['BASICS', 'BASIKOS']);
        $this->applyCategoryScope($query, $category, $filters);
        $this->applyPriceAndSizeFilters($query, $filters);
        $this->applySort($query, (string) ($filters['ordenamiento'] ?? 'Más recientes'));

        $products = $this->getProducts($query);
        $availability = $this->summarize($country, $products, $storeCode);
        $bySku = $availability['availabilityBySku'] ?? [];

        return $products
            ->filter(fn (object $product) => (bool) ($bySku[trim((string) $product->pro_codigo)]['hasStock'] ?? false))
            ->map(function (object $product) use ($bySku): array {
                $item = $this->legacyProduct($product, $bySku);
                $item['sello'] = 'https://stjacks.com/img/v2/icons/Icon%20awesome-tag.svg';

                return $item;
            })
            ->values()
            ->all();
    }

    private function productQuery(int $countryId): Builder
    {
        return DB::table('stj_productos as p')
            ->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->join('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->join('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO');
    }

    private function getProducts(Builder $query, ?int $limit = null)
    {
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get([
            'p.pro_id', 'p.pro_codigo', 'p.pro_nombre', 'p.pro_descripcion', 'p.pro_marca',
            'p.pro_oc_marca', 'p.pro_categoria', 'p.pro_sub_categoria', 'p.pro_tallas', 'c.cat_nombre', 'sc.sca_nombre',
            'pp.ppa_precio', 'pp.ppa_descuento', 'pp.ppa_origen_descuento', 'pp.ppa_promo_nombre',
            'pp.ppa_promo_logo', 'pp.ppa_tipo_descuento', 'pp.ppa_precio_tienda',
        ]);
    }

    private function summarize(object $country, $products, string $storeCode): array
    {
        return $this->availability->summarize(
            strtolower((string) $country->pai_codigo),
            $products->map(fn (object $product) => ['pro_codigo' => $product->pro_codigo])->all(),
            $storeCode,
        );
    }

    private function validatedContext(
        int $countryId,
        int $categoryId,
        string $storeCode,
        string $categoryField = 'categoria',
        string $storeField = 'tienda',
    ): array {
        $country = DB::table('stj_paises')->where('pai_id', $countryId)->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }

        $storeCode = trim($storeCode);
        if (! DB::table('stj_tiendas')->where('tie_pais', $countryId)->where('tie_codigo', $storeCode)->exists()) {
            throw ValidationException::withMessages([$storeField => 'La tienda no pertenece al pais seleccionado.']);
        }

        $category = DB::table('stj_categorias')->where('cat_id', $categoryId)
            ->first(['cat_id', 'cat_si_sub_otras', 'cat_sub_otras', 'cat_marca']);
        if (! $category) {
            throw ValidationException::withMessages([$categoryField => 'Categoria no encontrada.']);
        }

        return [$country, $category, $storeCode];
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

    private function applyCategoryScope(Builder $query, object $category, array $filters): void
    {
        if ((bool) $category->cat_si_sub_otras) {
            $subcategoryIds = collect(explode(',', (string) $category->cat_sub_otras))
                ->map(fn (string $id) => (int) trim($id))->filter()->unique()->all();
            $query->whereIn('p.pro_sub_categoria', $subcategoryIds);
        } else {
            $query->where('p.pro_categoria', (int) $category->cat_id);
            $subcategory = trim((string) ($filters['scat'] ?? ''));
            if ($subcategory !== '') {
                $query->where('p.pro_sub_categoria', (int) $subcategory);
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

    private function legacyProduct(object $product, array $availabilityBySku, bool $includeSeal = false): array
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
            'sello' => $includeSeal && trim((string) $product->ppa_promo_logo) !== ''
                ? 'https://stjacks.com/img/logos/'.trim((string) $product->ppa_promo_logo)
                : '',
            'precioCD' => number_format($discountedPrice, 2, '.', ''),
            'descripcion' => str_replace('-', '<br/>-', (string) $product->pro_descripcion),
            'categoria' => $product->pro_categoria,
            'subCategoria' => $product->pro_sub_categoria,
            'subCategoriaTxt' => (string) $product->sca_nombre,
            'categoriaTxt' => (string) $product->cat_nombre,
            'foto' => config('mobile.legacy_product_image_url').'/'.$sku.'.jpg?'.(string) $product->pro_nombre,
            'envioGratis' => 'NO',
            'Domicilio' => true,
            'Tienda' => true,
            'ppa_promo_nombre' => (string) ($product->ppa_promo_nombre ?? ''),
            'pro_tallas_list' => $product->pro_tallas ? explode(',', (string) $product->pro_tallas) : [],
            'availableSizes' => $availabilityBySku[$sku]['availableSizes'] ?? [],
            'hasStock' => (bool) ($availabilityBySku[$sku]['hasStock'] ?? false),
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
