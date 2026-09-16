<?php

namespace Tests\Unit;

use App\Support\CustomerPhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CustomerPhoneNumberTest extends TestCase
{
    #[DataProvider('numbers')]
    public function test_country_lengths(string $country, string $number, bool $expected): void
    {
        $this->assertSame($expected, CustomerPhoneNumber::valid($country, CustomerPhoneNumber::digits($number)));
    }

    public static function numbers(): array
    {
        return [
            ['SV', '7123-4567', true], ['SV', '7123 456', false],
            ['GT', '12345678', true], ['GT', '123456789', false],
            ['CR', '12345678', true], ['CR', '1234567', false],
            ['HN', '12345678', true], ['HN', '123456789', false],
            ['PA', '1234567', true], ['PA', '12345678', true], ['PA', '123456', false],
            ['US', '123456', true], ['US', '123456789012345', true], ['US', '12345', false],
            ['US', '1234567890123456', false],
        ];
    }
}
