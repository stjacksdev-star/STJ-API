<?php

namespace Tests\Unit;

use App\Services\Payments\CardBrandDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CardBrandDetectorTest extends TestCase
{
    #[DataProvider('cards')]
    public function test_it_detects_supported_card_brands(string $pan, string $expected): void
    {
        $this->assertSame($expected, (new CardBrandDetector)->detect($pan));
    }

    public static function cards(): array
    {
        return [
            'visa 16' => ['4111 1111 1111 1111', 'VISA'],
            'visa 13' => ['4222222222222', 'VISA'],
            'mastercard legacy lower' => ['5105105105105100', 'MASTERCARD'],
            'mastercard legacy upper' => ['5555555555554444', 'MASTERCARD'],
            'mastercard new lower' => ['2221000000000009', 'MASTERCARD'],
            'mastercard new upper' => ['2720990000000007', 'MASTERCARD'],
            'amex 34' => ['341111111111111', 'AMEX'],
            'amex 37' => ['378282246310005', 'AMEX'],
            'diners is not amex' => ['30569309025904', 'OTRO'],
            'unknown' => ['6011111111111117', 'OTRO'],
        ];
    }
}
