<?php

namespace App\Support;

class CustomerPhoneNumber
{
    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function valid(string $countryCode, string $digits): bool
    {
        $length = strlen($digits);

        return match (strtoupper($countryCode)) {
            'SV', 'GT', 'CR', 'HN' => $length === 8,
            'PA' => $length >= 7 && $length <= 8,
            default => $length >= 6 && $length <= 15,
        };
    }

    public static function message(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'SV', 'GT', 'CR', 'HN' => 'El teléfono debe contener exactamente 8 dígitos, con o sin guion.',
            'PA' => 'El número telefónico debe tener entre 7 y 8 dígitos.',
            default => 'El número telefónico debe tener entre 6 y 15 dígitos.',
        };
    }
}
