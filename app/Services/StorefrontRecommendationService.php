<?php

namespace App\Services;

use App\Models\StorefrontCart;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Support\StorefrontImageUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontRecommendationService
{
    private const PURCHASE_HISTORY_CACHE_MINUTES = 20;

    public function __construct(
        private readonly ProductListAvailabilityService $productListAvailability,
        private readonly StorefrontProductPromotionPresenter $promotionPresenter,
    ) {}

    public function recommend(string $countryCode, string $placement, StorefrontVisitor $visitor, ?StorefrontCustomer $customer = null, ?int $productId = null, int $limit = 10): array
    {
        $limit = min(10, max(1, $limit));
        $country = DB::table('stj_paises')->where('pai_codigo', strtoupper($countryCode))->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            return [];
        }

        $cart = $this->cart((int) $country->pai_id, $visitor, $customer);
        if ($placement === 'RECENTLY_VIEWED') {
            return $this->recentlyViewed((int) $country->pai_id, $visitor, $customer, $cart, $limit);
        }

        $seedIds = $productId ? [$productId] : ($cart?->items->sortByDesc('cad_creado_en')->take(3)->pluck('cad_producto_id')->map(fn ($id) => (int) $id)->all() ?? []);
        $excluded = collect($cart?->items()->pluck('cad_producto_id') ?? [])->map(fn ($id) => (int) $id)->push($productId)->filter()->unique()->all();
        $seeds = $this->baseProducts((int) $country->pai_id)->whereIn('p.pro_id', $seedIds)->get();
        $candidates = $this->baseProducts((int) $country->pai_id)
            ->whereNotIn('p.pro_id', $excluded ?: [0])
            ->limit(180)->get();
        $popular = $this->recentPopularity((int) $country->pai_id, $candidates->pluck('pro_id')->all());

        $cartRanked = $seeds->isEmpty() ? collect() : $candidates->map(function ($candidate) use ($seeds, $popular) {
            $best = ['score' => -1, 'reason' => 'POPULAR'];
            foreach ($seeds as $seed) {
                $score = 0;
                $reason = 'POPULAR';
                if ($this->same($candidate->pro_coleccion, $seed->pro_coleccion)) {
                    $score += 40;
                    $reason = 'SAME_COLLECTION';
                }
                if ((int) $candidate->pro_categoria === (int) $seed->pro_categoria) {
                    $score += 25;
                    if ($reason === 'POPULAR') {
                        $reason = 'SAME_CATEGORY';
                    }
                }
                if ($this->same($candidate->pro_marca, $seed->pro_marca)) {
                    $score += 15;
                    if ($reason === 'POPULAR') {
                        $reason = 'SAME_BRAND';
                    }
                }
                if ($this->same($candidate->pro_oc_genero, $seed->pro_oc_genero)) {
                    $score += 10;
                }
                $distance = abs((float) $candidate->ppa_precio - (float) ($seed->ppa_precio ?? $candidate->ppa_precio));
                $score += max(0, 10 - min(10, $distance));
                if ($reason === 'POPULAR' && $distance <= 5) {
                    $reason = 'SIMILAR_PRICE';
                }
                $score += min(10, (int) ($popular[$candidate->pro_id] ?? 0));
                if ($score > $best['score']) {
                    $best = compact('score', 'reason');
                }
            }
            $candidate->recommendation_score = 500 + $best['score'];
            $candidate->recommendation_reason = $best['reason'];
            $candidate->recommendation_source = 'cart';

            return $candidate;
        })->filter(fn ($candidate) => $candidate->recommendation_score >= 525)
            ->sort(fn ($a, $b) => [$b->recommendation_score, $b->ppa_es_popular, -$b->pro_id] <=> [$a->recommendation_score, $a->ppa_es_popular, -$a->pro_id]);

        $historyRanked = $placement === 'CART_RECOMMENDATIONS' && $customer
            ? $this->purchaseHistoryRanked((int) $country->pai_id, $customer, $candidates, $excluded)
            : collect();
        $fallback = $candidates->map(function ($candidate) use ($popular) {
            $candidate = clone $candidate;
            $candidate->recommendation_score = min(20, (int) ($popular[$candidate->pro_id] ?? 0)) + ((int) $candidate->ppa_es_popular * 20);
            $candidate->recommendation_reason = 'POPULAR';
            $candidate->recommendation_source = 'fallback';

            return $candidate;
        })->sortByDesc('recommendation_score');
        $ranked = $cartRanked
            ->concat($historyRanked)
            ->concat($fallback)
            ->unique('pro_id')
            ->values();

        return $this->normalizeRecommendations(
            $this->available($ranked, $cart, (int) $country->pai_id, strtolower((string) $country->pai_codigo))->take($limit)->values(),
            $cart,
            (int) $country->pai_id,
            (string) $country->pai_codigo,
        );
    }

    public static function forgetPurchaseHistory(int $customerId, int $countryId): void
    {
        Cache::forget(self::purchaseHistoryCacheKey($customerId, $countryId));
    }

    private function purchaseHistoryRanked(int $countryId, StorefrontCustomer $customer, Collection $candidates, array $excluded): Collection
    {
        $history = Cache::remember(
            self::purchaseHistoryCacheKey((int) $customer->getKey(), $countryId),
            now()->addMinutes(self::PURCHASE_HISTORY_CACHE_MINUTES),
            fn () => $this->purchaseHistory($countryId, (int) $customer->getKey()),
        );
        $seeds = collect($history['products'] ?? []);
        if ($seeds->isEmpty()) {
            return collect();
        }
        $purchasedIds = collect($history['product_ids'] ?? [])->map(fn ($id) => (int) $id)->all();

        return $candidates
            ->reject(fn ($candidate) => in_array((int) $candidate->pro_id, $purchasedIds, true) || in_array((int) $candidate->pro_id, $excluded, true))
            ->map(function ($candidate) use ($seeds) {
                $best = ['score' => 0, 'reason' => 'PURCHASE_HISTORY'];
                foreach ($seeds as $seed) {
                    $character = $this->same($candidate->pro_personaje, $seed->pro_personaje)
                        || $this->same($candidate->pro_oc_personaje, $seed->pro_oc_personaje)
                        || $this->same($candidate->pro_oc_licencia, $seed->pro_oc_licencia);
                    $categoryGender = (int) $candidate->pro_categoria === (int) $seed->pro_categoria
                        && $this->same($candidate->pro_oc_genero, $seed->pro_oc_genero);
                    $brandCollection = $this->same($candidate->pro_marca, $seed->pro_marca)
                        || $this->same($candidate->pro_coleccion, $seed->pro_coleccion);
                    $score = ($character ? 300 : 0) + ($categoryGender ? 200 : 0) + ($brandCollection ? 100 : 0);
                    $reason = $character ? 'PURCHASE_CHARACTER' : ($categoryGender ? 'PURCHASE_CATEGORY_GENDER' : ($brandCollection ? 'PURCHASE_BRAND_COLLECTION' : 'PURCHASE_HISTORY'));
                    if ($score > $best['score']) {
                        $best = compact('score', 'reason');
                    }
                }
                $candidate = clone $candidate;
                $candidate->recommendation_score = $best['score'];
                $candidate->recommendation_reason = $best['reason'];
                $candidate->recommendation_source = 'purchase_history';

                return $candidate;
            })
            ->filter(fn ($candidate) => $candidate->recommendation_score > 0)
            ->sortByDesc('recommendation_score')
            ->values();
    }

    private function purchaseHistory(int $countryId, int $customerId): array
    {
        $payments = DB::table('stj_pedidos_pago as pay')
            ->join('stj_pedidos as orders', 'orders.ped_id', '=', 'pay.ppa_pedido')
            ->leftJoin('stj_carritos as carts', 'carts.car_pedido_id', '=', 'orders.ped_id')
            ->where('pay.ppa_estado', 'APROBADA')
            ->where('orders.ped_id_pais', $countryId)
            ->where(function ($query) use ($customerId) {
                $query->where('orders.ped_user', $customerId)
                    ->orWhere('carts.car_usu_id', $customerId);
            })
            ->orderByDesc('pay.ppa_fecha')
            ->limit(5)
            ->get(['orders.ped_id', 'pay.ppa_ref']);
        if ($payments->isEmpty()) {
            return ['product_ids' => [], 'products' => []];
        }
        $productIds = DB::table('stj_pedidos_detalle')
            ->where('car_pais', $countryId)
            ->where('car_accion', 'AGREGADO')
            ->whereIn('car_ref', $payments->pluck('ppa_ref')->all())
            ->whereNotNull('car_producto')
            ->pluck('car_producto')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return [
            'product_ids' => $productIds->all(),
            'products' => DB::table('stj_productos as p')
                ->whereIn('p.pro_id', $productIds->all())
                ->get(['p.pro_id', 'p.pro_categoria', 'p.pro_coleccion', 'p.pro_marca', 'p.pro_personaje', 'p.pro_oc_personaje', 'p.pro_oc_licencia', 'p.pro_oc_genero'])
                ->all(),
        ];
    }

    private static function purchaseHistoryCacheKey(int $customerId, int $countryId): string
    {
        return "storefront:recommendations:purchase_history:{$customerId}:{$countryId}";
    }

    private function recentlyViewed(int $countryId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, ?StorefrontCart $cart, int $limit): array
    {
        $events = DB::table('stj_cliente_eventos')->where('cev_tipo', 'PRODUCT_VIEW')->where('cev_pais_id', $countryId);
        $customer ? $events->where('cev_usu_id', $customer->getKey()) : $events->where('cev_visitante_id', $visitor->getKey())->whereNull('cev_usu_id');
        $ids = $events->whereNotNull('cev_producto_id')->orderByDesc('cev_ocurrido_en')->pluck('cev_producto_id')->unique()->take(40)->map(fn ($id) => (int) $id)->all();
        if (! $ids) {
            return [];
        }
        $order = array_flip($ids);
        $rows = $this->baseProducts($countryId)->whereIn('p.pro_id', $ids)->get()->sortBy(fn ($row) => $order[$row->pro_id]);
        $code = (string) DB::table('stj_paises')->where('pai_id', $countryId)->value('pai_codigo');

        $available = $this->available($rows, $cart, $countryId, strtolower($code))->take($limit)->values()->map(function ($row) {
            $row->recommendation_reason = 'RECENTLY_VIEWED';

            return $row;
        });

        return $this->normalizeRecommendations($available, $cart, $countryId, $code);
    }

    private function baseProducts(int $countryId)
    {
        return DB::table('stj_productos as p')->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->where('pp.ppa_pais', $countryId)->where('pp.ppa_estado', 'ACTIVO')->where('p.pro_estatus', 'ACTIVO')->where('pp.ppa_precio', '>', 0)
            ->select(['p.pro_id', 'p.pro_codigo', 'p.pro_nombre', 'p.pro_categoria', 'p.pro_coleccion', 'p.pro_marca', 'p.pro_personaje', 'p.pro_oc_personaje', 'p.pro_oc_licencia', 'p.pro_oc_genero', 'p.pro_tallas', 'p.pro_thumbs', 'p.pro_registro', 'pp.ppa_precio', 'pp.ppa_precio_talla', 'pp.ppa_descuento', 'pp.ppa_es_popular', 'c.cat_nombre'])
            ->selectRaw("CASE WHEN pp.ppa_precio_talla = 'SI' THEN COALESCE((SELECT MIN(pta.pta_precio) FROM stj_producto_talla pta WHERE pta.pta_pais = pp.ppa_pais AND pta.pta_producto = p.pro_id AND pta.pta_precio > 0), pp.ppa_precio) ELSE pp.ppa_precio END AS display_price");
    }

    private function available(Collection $rows, ?StorefrontCart $cart, int $countryId, string $countryCode): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }
        $store = $cart?->car_tienda_codigo_snapshot ?: config("inventory.domicilio_store_by_country.{$countryCode}");
        if (! $store) {
            return collect();
        }
        $availability = $this->productListAvailability->summarize($countryCode, $rows->all(), (string) $store);
        $availabilityBySku = $availability['availabilityBySku'] ?? [];

        return $rows
            ->filter(function ($row) use ($availabilityBySku) {
                $summary = $availabilityBySku[trim((string) $row->pro_codigo)] ?? null;

                if (! ($summary['hasStock'] ?? false)) {
                    return false;
                }

                $row->available_sizes = $summary['availableSizes'] ?? [];
                $row->stock_total = (int) ($summary['totalQuantity'] ?? 0);

                return true;
            })
            ->values();
    }

    private function cart(int $countryId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): ?StorefrontCart
    {
        return StorefrontCart::query()->with('items')->where('car_pais_id', $countryId)->where('car_estado', 'ACTIVO')
            ->when($customer, fn ($q) => $q->where('car_usu_id', $customer->getKey()), fn ($q) => $q->whereNull('car_usu_id')->where('car_visitante_id', $visitor->getKey()))
            ->latest('car_ultima_actividad_en')->first();
    }

    private function recentPopularity(int $countryId, array $ids): array
    {
        return DB::table('stj_cliente_eventos')->where('cev_pais_id', $countryId)->whereIn('cev_producto_id', $ids ?: [0])->whereIn('cev_tipo', ['PRODUCT_VIEW', 'ADD_TO_CART', 'PURCHASE'])->where('cev_ocurrido_en', '>=', now()->subDays(30))->groupBy('cev_producto_id')->selectRaw('cev_producto_id, COUNT(*) total')->pluck('total', 'cev_producto_id')->all();
    }

    private function normalizeRecommendations(Collection $rows, ?StorefrontCart $cart, int $countryId, string $countryCode): array
    {
        $commercial = $this->promotionPresenter->resolve($rows, $countryId, $countryCode, [
            'checkoutType' => strtoupper((string) ($cart?->car_tipo ?? 'DOMICILIO')) === 'TIENDA' ? 'TIENDA' : 'DOMICILIO',
            'storeCode' => $cart?->car_tienda_codigo_snapshot,
        ]);

        return $rows->values()->map(fn (object $row, int $index) => $this->normalize(
            $row,
            $index + 1,
            $countryCode,
            $commercial->get((int) $row->pro_id),
        ))->all();
    }

    private function normalize(object $row, int $position, string $countryCode, ?array $commercial = null): array
    {
        $promotion = $commercial['promotion'] ?? null;
        $regular = round((float) ($row->display_price ?? $row->ppa_precio), 2);
        $final = round((float) ($commercial['finalTotal'] ?? $regular), 2);
        $hasDiscount = $promotion !== null
            && (int) round($final * 100) < (int) round($regular * 100);
        $currency = ['GT' => 'GTQ', 'CR' => 'CRC', 'DO' => 'DOP', 'HN' => 'HNL'][strtoupper($countryCode)] ?? 'USD';
        $image = StorefrontImageUrl::image((string) $row->pro_thumbs, 'p400');

        $source = $row->recommendation_source ?? 'fallback';

        return ['product_id' => (int) $row->pro_id, 'id' => (int) $row->pro_id, 'slug' => Str::slug($row->pro_nombre).'-'.$row->pro_id, 'sku' => $row->pro_codigo, 'nombre' => $row->pro_nombre, 'name' => $row->pro_nombre, 'marca' => $row->pro_marca, 'brand' => $row->pro_marca, 'coleccion' => $row->pro_coleccion, 'category' => $row->cat_nombre, 'image' => $image, 'imageUrl' => $image, 'price' => $final, 'previousPrice' => $hasDiscount ? $regular : null, 'priceFrom' => strtoupper((string) $row->ppa_precio_talla) === 'SI', 'currency' => $currency, 'available' => true, 'hasStock' => true, 'stockTotal' => (int) ($row->stock_total ?? 0), 'recommendation_reason' => $row->recommendation_reason ?? 'POPULAR', 'recommendation_source' => $source, 'position' => $position, 'badge' => $source === 'purchase_history' ? 'Recomendado para ti' : ($promotion['displayLabel'] ?? 'Disponible'), 'promotion' => $promotion];
    }

    private function same(mixed $a, mixed $b): bool
    {
        return trim(strtolower((string) $a)) !== '' && trim(strtolower((string) $a)) === trim(strtolower((string) $b));
    }
}
