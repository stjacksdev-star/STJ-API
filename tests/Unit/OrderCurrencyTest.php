<?php

namespace Tests\Unit;

use App\Support\OrderCurrency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderCurrencyTest extends TestCase
{
    #[DataProvider('countries')]
    public function test_order_email_currency_matches_country(int $countryId, string $countryCode, string $symbol): void
    {
        $this->assertSame($symbol, OrderCurrency::symbolForCountryId($countryId));
        $this->assertSame($symbol, OrderCurrency::symbolForCountryCode($countryCode));
    }

    public static function countries(): array
    {
        return [
            'El Salvador uses dollars' => [1, 'SV', '$'],
            'Guatemala uses quetzales' => [2, 'GT', 'Q'],
            'Costa Rica uses colones' => [3, 'CR', '₡'],
            'Panama uses dollars' => [5, 'PA', '$'],
            'Honduras uses lempiras' => [7, 'HN', 'L'],
        ];
    }
}
