<?php

namespace App\Support;

class OrderCurrency
{
    public static function symbolForCountryId(int $countryId): string
    {
        return self::symbolForCountryCode(match ($countryId) {
            1 => 'SV',
            2 => 'GT',
            3 => 'CR',
            5 => 'PA',
            6 => 'DO',
            7 => 'HN',
            8 => 'VE',
            default => '',
        });
    }

    public static function symbolForCountryCode(string $countryCode): string
    {
        return match (strtoupper(trim($countryCode))) {
            'GT' => 'Q',
            'CR' => '₡',
            'HN' => 'L',
            'DO' => 'RD$',
            'VE' => 'Bs.',
            'SV', 'PA' => '$',
            default => '$',
        };
    }
}
