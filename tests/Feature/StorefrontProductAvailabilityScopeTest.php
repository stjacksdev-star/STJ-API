<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveStorefrontVisitor;
use App\Services\ProductDetailAvailabilityService;
use Mockery;
use Tests\TestCase;

class StorefrontProductAvailabilityScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ResolveStorefrontVisitor::class);
    }

    public function test_product_card_can_request_product_list_inventory_rule(): void
    {
        $availability = Mockery::mock(ProductDetailAvailabilityService::class);
        $availability->shouldReceive('forCountryAndSlug')
            ->once()
            ->with('sv', 'producto-10', null, 'product_list')
            ->andReturn([
                'product' => ['id' => 10],
                'sizes' => [],
            ]);
        $this->app->instance(ProductDetailAvailabilityService::class, $availability);

        $this->getJson('/api/storefront/product/sv/producto-10/availability?scope=product_list')
            ->assertOk();
    }

    public function test_product_availability_uses_product_detail_rule_by_default(): void
    {
        $availability = Mockery::mock(ProductDetailAvailabilityService::class);
        $availability->shouldReceive('forCountryAndSlug')
            ->once()
            ->with('sv', 'producto-10', null, 'product_detail')
            ->andReturn([
                'product' => ['id' => 10],
                'sizes' => [],
            ]);
        $this->app->instance(ProductDetailAvailabilityService::class, $availability);

        $this->getJson('/api/storefront/product/sv/producto-10/availability')
            ->assertOk();
    }
}
