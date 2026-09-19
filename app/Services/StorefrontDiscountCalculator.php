<?php

namespace App\Services;

class StorefrontDiscountCalculator
{
    public static function promotionPercentage(?array $promotion): ?float
    {
        if (! in_array($promotion['type'] ?? null, ['DESCUENTO', 'DESCUENTO-SKU'], true)
            || ! is_numeric($promotion['discountPercentage'] ?? null)) {
            return null;
        }

        return max(0, min(100, (float) $promotion['discountPercentage']));
    }

    public static function discountCents(int $baseCents, float $percentage): int
    {
        return (int) round($baseCents * $percentage / 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function percentage(int $baseCents, int $discountCents, ?float $configured = null): float
    {
        if ($baseCents <= 0) {
            return 0.0;
        }
        // Only retain a configured percentage when it describes the entire line.
        // Conditional allocations, price targets and capped coupons use the actual benefit.
        if ($configured !== null && self::discountCents($baseCents, $configured) === $discountCents) {
            return $configured;
        }

        return round($discountCents * 100 / $baseCents, 6);
    }

    public static function subtotalCents(int $baseCents, float $percentage): int
    {
        return max(0, $baseCents - self::discountCents($baseCents, $percentage));
    }
}
