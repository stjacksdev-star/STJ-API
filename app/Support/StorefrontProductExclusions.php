<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorefrontProductExclusions
{
    public static function apply(Builder $query, string $productAlias = 'product'): Builder
    {
        $categories = self::categoryIds();

        if ($categories !== []) {
            $query->whereNotIn("{$productAlias}.pro_categoria", $categories);
        }

        return $query;
    }

    /** @param iterable<int, int|string> $productIds */
    public static function excludedProductIds(iterable $productIds): Collection
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $categories = self::categoryIds();

        if ($ids->isEmpty() || $categories === [] || ! Schema::hasTable('stj_productos')) {
            return collect();
        }

        return DB::table('stj_productos')
            ->whereIn('pro_id', $ids->all())
            ->whereIn('pro_categoria', $categories)
            ->pluck('pro_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /** @return array<int, int> */
    private static function categoryIds(): array
    {
        return collect(config('storefront.excluded_product_categories', [17]))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
