<?php

namespace App\Services\Payments;

class CardBrandDetector
{
    public function detect(string $pan): string
    {
        $digits = preg_replace('/\D+/', '', $pan) ?? '';

        if (preg_match('/^4\d{12}(?:\d{3})?(?:\d{3})?$/', $digits)) {
            return 'VISA';
        }

        if (preg_match('/^3[47]\d{13}$/', $digits)) {
            return 'AMEX';
        }

        if (preg_match('/^5[1-5]\d{14}$/', $digits)) {
            return 'MASTERCARD';
        }

        if (strlen($digits) === 16) {
            $prefix = (int) substr($digits, 0, 4);
            if ($prefix >= 2221 && $prefix <= 2720) {
                return 'MASTERCARD';
            }
        }

        return 'OTRO';
    }
}
