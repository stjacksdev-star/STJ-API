<?php

namespace Tests\Unit;

use App\Services\Dashboard\OrderReferenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DashboardOrderRoundingTest extends TestCase
{
    public function test_dashboard_uses_the_same_discount_rounding_as_checkout(): void
    {
        $reflection = new ReflectionClass(OrderReferenceService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('subtotalAfterPercentageDiscount');

        $this->assertSame(6.47, $method->invoke($service, 12.95, 1, 50.0));
        $this->assertSame(10.97, $method->invoke($service, 21.95, 1, 50.0));
        $this->assertSame(3.97, $method->invoke($service, 7.95, 1, 50.0));
    }
}
