<?php

namespace App\Support;

use Illuminate\Support\Str;

class CouponProductScope
{
    private const CATEGORY_GROUPS = [
        'niñas' => 'girls',
        'niños' => 'boys',
        'toddler niñas' => 'toddlers',
        'toddler niños' => 'toddlers',
        'bebas' => 'babies',
        'bebos' => 'babies',
        'bebes unisex' => 'babies',
        'damas' => 'adults',
        'caballeros' => 'adults',
        'teen chicas' => 'teens',
        'teen chicos' => 'teens',
        'ropa interior y accesorios' => 'accessories',
        'cuidado personal' => 'accessories',
        'otros' => 'accessories',
    ];

    public static function details(object $coupon, string $countryCode, string $baseUrl): array
    {
        $scope = strtoupper((string) ($coupon->productScope ?? $coupon->che_tipo_productos ?? 'NA'));
        $category = trim((string) ($coupon->categoryName ?? $coupon->genero_nombre ?? ''));
        $collectionId = (int) ($coupon->collectionId ?? $coupon->che_coleccion ?? 0);
        $collection = trim((string) ($coupon->collectionName ?? $coupon->coleccion_nombre ?? ''));
        $headerId = (int) ($coupon->headerId ?? $coupon->che_id ?? 0);
        $name = trim((string) ($coupon->che_nombre_comercial ?? $coupon->che_nombre ?? 'cupon'));
        $regularOnly = strtoupper((string) ($coupon->promotionRule ?? $coupon->che_aplica_promo ?? 'REGULAR')) === 'REGULAR';
        $localizedBase = self::localizedBase($baseUrl, $countryCode);

        return match ($scope) {
            'PLA' => [
                'scope' => $scope,
                'label' => 'Aplica a productos seleccionados.',
                'url' => $headerId > 0 ? $localizedBase.'/cupones/'.$headerId.'/'.Str::slug($name) : null,
            ],
            'GEN' => [
                'scope' => $scope,
                'label' => $category !== '' ? "Aplica a productos de la categoría {$category}." : 'Aplica a productos de la categoría seleccionada.',
                'url' => $category !== '' ? $localizedBase.'/catalogo?'.http_build_query(self::categoryQuery($category)) : null,
            ],
            'COL' => [
                'scope' => $scope,
                'label' => $collection !== '' ? "Aplica a productos de la colección {$collection}." : 'Aplica a productos de la colección seleccionada.',
                'url' => $collectionId > 0 ? $localizedBase.'/colecciones/'.$collectionId.'/'.Str::slug($collection ?: $name) : null,
            ],
            default => [
                'scope' => $scope,
                'label' => $regularOnly ? 'Aplica a todos los productos de precio regular.' : 'Aplica a todos los productos elegibles, incluso con promoción.',
                'url' => null,
            ],
        };
    }

    private static function categoryQuery(string $category): array
    {
        $normalized = mb_strtolower(trim($category));
        $group = self::CATEGORY_GROUPS[$normalized] ?? null;

        return $group ? ['group' => $group] : ['category' => $category];
    }

    private static function localizedBase(string $baseUrl, string $countryCode): string
    {
        $base = rtrim($baseUrl, '/');
        $base = preg_replace('#/(sv|gt|cr|hn|pa|do)$#i', '', $base) ?: $base;

        return $base.'/'.strtolower($countryCode);
    }
}
