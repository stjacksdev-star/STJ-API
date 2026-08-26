<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Support\StorefrontImageUrl;
use App\Support\StorefrontProductExclusions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontFavoriteService
{
    public function __construct(
        private readonly StorefrontProductPromotionPresenter $promotionPresenter,
    ) {}

    public function list(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): array
    {
        $country = $this->country($countryCode);
        $owner = DB::table('stj_favoritos as f')->where('f.fav_pais', $country->pai_id);
        $customer
            ? $owner->where('f.fav_usuario', $customer->getKey())
            : $owner->where('f.fav_visitante', $visitor->getKey())->whereNull('f.fav_usuario');

        $rows = $owner
            ->join('stj_productos as p', 'p.pro_id', '=', 'f.fav_producto')
            ->join('stj_producto_pais as pp', function ($join) {
                $join->on('pp.ppa_producto', '=', 'p.pro_id')->on('pp.ppa_pais', '=', 'f.fav_pais');
            })
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->where('p.pro_estatus', 'ACTIVO')->where('pp.ppa_estado', 'ACTIVO');
        StorefrontProductExclusions::apply($rows, 'p');
        $rows = $rows
            ->orderByDesc('f.fav_updated_at')->orderByDesc('f.fav_id')
            ->select(['f.fav_id', 'p.pro_id', 'p.pro_codigo', 'p.pro_nombre', 'p.pro_marca', 'p.pro_thumbs', 'pp.ppa_precio', 'pp.ppa_precio_talla', 'pp.ppa_descuento', 'c.cat_nombre'])
            ->selectRaw("CASE WHEN pp.ppa_precio_talla = 'SI' THEN COALESCE((SELECT MIN(pta.pta_precio) FROM stj_producto_talla pta WHERE pta.pta_pais = pp.ppa_pais AND pta.pta_producto = p.pro_id AND pta.pta_precio > 0), pp.ppa_precio) ELSE pp.ppa_precio END AS display_price")
            ->get();

        $commercial = $this->promotionPresenter->resolve(
            $rows,
            (int) $country->pai_id,
            (string) $country->pai_codigo,
        );

        return $rows->map(fn ($row) => $this->product(
            $row,
            (string) $country->pai_codigo,
            $commercial->get((int) $row->pro_id),
        ))->all();
    }

    public function add(string $countryCode, int $productId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, string $origin = 'WEB'): array
    {
        $country = $this->country($countryCode);
        $valid = DB::table('stj_productos as p')->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->where('p.pro_id', $productId)->where('p.pro_estatus', 'ACTIVO')->where('pp.ppa_pais', $country->pai_id)->where('pp.ppa_estado', 'ACTIVO');
        StorefrontProductExclusions::apply($valid, 'p');
        $valid = $valid->exists();
        if (! $valid) throw ValidationException::withMessages(['product_id' => 'El producto no está disponible en este país.']);

        $ownerColumn = $customer ? 'fav_usuario' : 'fav_visitante';
        $ownerId = $customer?->getKey() ?? $visitor->getKey();
        DB::transaction(function () use ($country, $productId, $ownerColumn, $ownerId, $customer, $visitor, $origin) {
            DB::table('stj_favoritos')->updateOrInsert(
                ['fav_pais' => $country->pai_id, $ownerColumn => $ownerId, 'fav_producto' => $productId],
                ['fav_visitante' => $customer ? null : $visitor->getKey(), 'fav_usuario' => $customer?->getKey(), 'fav_origen' => strtoupper($origin), 'fav_updated_at' => now(), 'fav_created_at' => now()],
            );
        });

        return $this->list($countryCode, $visitor, $customer);
    }

    public function remove(string $countryCode, int $productId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): array
    {
        $country = $this->country($countryCode);
        $query = DB::table('stj_favoritos')->where('fav_pais', $country->pai_id)->where('fav_producto', $productId);
        $customer ? $query->where('fav_usuario', $customer->getKey()) : $query->where('fav_visitante', $visitor->getKey())->whereNull('fav_usuario');
        $query->delete();
        return $this->list($countryCode, $visitor, $customer);
    }

    public function merge(StorefrontVisitor $visitor, StorefrontCustomer $customer): void
    {
        DB::transaction(function () use ($visitor, $customer) {
            $guestRows = DB::table('stj_favoritos')->where('fav_visitante', $visitor->getKey())->whereNull('fav_usuario')->get();
            foreach ($guestRows as $favorite) {
                DB::table('stj_favoritos')->updateOrInsert(
                    ['fav_pais' => $favorite->fav_pais, 'fav_usuario' => $customer->getKey(), 'fav_producto' => $favorite->fav_producto],
                    ['fav_visitante' => null, 'fav_origen' => $favorite->fav_origen ?: 'WEB', 'fav_updated_at' => now(), 'fav_created_at' => $favorite->fav_created_at ?: now()],
                );
            }
            DB::table('stj_favoritos')->where('fav_visitante', $visitor->getKey())->whereNull('fav_usuario')->delete();
        });
    }

    public function consolidated(StorefrontCustomer $customer): array
    {
        return DB::table('stj_favoritos as f')->join('stj_paises as p', 'p.pai_id', '=', 'f.fav_pais')
            ->where('f.fav_usuario', $customer->getKey())->orderBy('p.pai_codigo')->orderByDesc('f.fav_updated_at')
            ->get(['p.pai_codigo', 'f.fav_producto'])->groupBy(fn ($row) => strtolower($row->pai_codigo))
            ->map(fn ($rows) => $rows->pluck('fav_producto')->map(fn ($id) => (int) $id)->values()->all())->all();
    }

    private function country(string $code): object
    {
        $country = DB::table('stj_paises')->where('pai_codigo', strtoupper($code))->first(['pai_id', 'pai_codigo']);
        if (! $country) throw ValidationException::withMessages(['country' => 'El país no es válido.']);
        return $country;
    }

    private function product(object $row, string $countryCode, ?array $commercial = null): array
    {
        $promotion = $commercial['promotion'] ?? null;
        $regular = round((float) ($row->display_price ?? $row->ppa_precio), 2);
        $final = round((float) ($commercial['finalTotal'] ?? $regular), 2);
        $hasDiscount = $promotion !== null
            && (int) round($final * 100) < (int) round($regular * 100);
        $currency = ['GT' => 'GTQ', 'CR' => 'CRC', 'DO' => 'DOP', 'HN' => 'HNL'][strtoupper($countryCode)] ?? 'USD';
        $image = StorefrontImageUrl::image((string) $row->pro_thumbs, 'p400');
        return ['favoriteId' => (int) $row->fav_id, 'id' => (int) $row->pro_id, 'product_id' => (int) $row->pro_id, 'slug' => Str::slug($row->pro_nombre).'-'.$row->pro_id, 'sku' => $row->pro_codigo, 'name' => $row->pro_nombre, 'brand' => $row->pro_marca, 'category' => $row->cat_nombre, 'imageUrl' => $image, 'price' => $final, 'previousPrice' => $hasDiscount ? $regular : null, 'currency' => $currency, 'badge' => $promotion['displayLabel'] ?? 'Favorito', 'promotion' => $promotion];
    }
}
