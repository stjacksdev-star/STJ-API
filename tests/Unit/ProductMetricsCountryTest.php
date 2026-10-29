<?php

namespace Tests\Unit;

use App\Services\ProductMetricsCalculator;
use PHPUnit\Framework\TestCase;

class ProductMetricsCountryTest extends TestCase
{
    public function test_venezuela_is_supported_by_product_metrics(): void
    {
        $this->assertContains(8, ProductMetricsCalculator::COUNTRIES);
    }
}
