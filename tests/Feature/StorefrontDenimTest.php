<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveStorefrontVisitor;
use App\Services\StorefrontDenimService;
use Mockery;
use Tests\TestCase;

class StorefrontDenimTest extends TestCase
{
    public function test_endpoint_exposes_one_shared_responsive_landing_contract(): void
    {
        $this->withoutMiddleware(ResolveStorefrontVisitor::class);

        $service = Mockery::mock(StorefrontDenimService::class);
        $service->shouldReceive('landing')->once()->andReturn([
            'banner' => null,
            'heroSlider' => [['mobileImage' => 'https://cdn.test/mobile.jpg']],
            'featuredFits' => ['title' => 'Fits destacados', 'items' => []],
            'details' => ['title' => 'Detalles', 'columns' => []],
            'video' => ['srcMobile' => 'https://cdn.test/mobile.mp4'],
            'audienceLinks' => [],
        ]);
        $this->app->instance(StorefrontDenimService::class, $service);

        $this->getJson('/api/storefront/denim/sv')
            ->assertOk()
            ->assertJsonPath('data.heroSlider.0.mobileImage', 'https://cdn.test/mobile.jpg')
            ->assertJsonPath('data.video.srcMobile', 'https://cdn.test/mobile.mp4');
    }
}
