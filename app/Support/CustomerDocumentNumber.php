<?php

namespace App\Support;

class CustomerDocumentNumber
{
    public static function types(string $country): array
    {
        $common = ['Carné de residente', 'Pasaporte', 'Licencia de conducir', 'Otro'];

        return match (strtoupper($country)) {
            'SV' => ['DUI', ...$common],
            'GT' => ['DPI', ...$common],
            'CR' => ['Cédula', 'DIMEX', 'Pasaporte', 'Licencia de conducir', 'Otro'],
            'PA' => ['Cédula', ...$common],
            'HN' => ['DNI', ...$common],
            default => ['Pasaporte', 'Otro'],
        };
    }

    public static function normalize(string $country, string $type, string $number): string
    {
        $number = trim($number);
        if (in_array(strtoupper($country).':'.$type, ['SV:DUI', 'GT:DPI', 'CR:Cédula', 'HN:DNI'], true)) {
            return preg_replace('/[\s-]+/', '', $number) ?? '';
        }

        return $number;
    }

    public static function valid(string $country, string $type, string $number): bool
    {
        if (! in_array($type, self::types($country), true)) return false;

        $normalized = self::normalize($country, $type, $number);
        if (strtoupper($country) === 'SV' && $type === 'DUI') return (bool) preg_match('/^\d{9}$/', $normalized);
        if (strtoupper($country) === 'GT' && $type === 'DPI') return (bool) preg_match('/^\d{13}$/', $normalized);
        if (strtoupper($country) === 'CR' && $type === 'Cédula') return (bool) preg_match('/^\d{9}$/', $normalized);
        if (strtoupper($country) === 'HN' && $type === 'DNI') return (bool) preg_match('/^\d{13}$/', $normalized);

        return $normalized !== '' && mb_strlen($normalized) <= 50;
    }

    public static function message(string $country, string $type): string
    {
        if (strtoupper($country) === 'SV' && $type === 'DUI') return 'El DUI debe contener exactamente 9 dígitos, con o sin guion.';
        if (strtoupper($country) === 'GT' && $type === 'DPI') return 'El DPI/CUI debe contener exactamente 13 dígitos; puedes usar espacios o guiones.';
        if (strtoupper($country) === 'CR' && $type === 'Cédula') return 'La cédula debe contener exactamente 9 dígitos; puedes usar espacios o guiones.';
        if (strtoupper($country) === 'HN' && $type === 'DNI') return 'El DNI debe contener exactamente 13 dígitos; puedes usar espacios o guiones.';

        return 'Selecciona un tipo de documento válido e ingresa su número.';
    }
}
