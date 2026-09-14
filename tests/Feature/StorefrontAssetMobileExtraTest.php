<?php

namespace Tests\Feature;

use App\Services\StorefrontAssetService;
use Tests\TestCase;

class StorefrontAssetMobileExtraTest extends TestCase
{
    private string $path;

    private string|false $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('app/storefront/assets.json');
        $this->original = is_file($this->path) ? file_get_contents($this->path) : false;
        @mkdir(dirname($this->path), 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->original === false) {
            @unlink($this->path);
        } else {
            file_put_contents($this->path, $this->original);
        }

        parent::tearDown();
    }

    public function test_mobile_extra_collection_asset_is_exposed_in_its_own_mobile_column(): void
    {
        file_put_contents($this->path, json_encode([
            'countries' => [
                'sv' => [
                    'assets' => [
                        'lo-mas-nuevo' => [[
                            'id' => 10,
                            'order' => 5,
                            'position' => 'MOVIL-EXTRA',
                            'image' => null,
                            'mobileImage' => '/assets/mobile-extra.jpg',
                            'link' => null,
                        ]],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = app(StorefrontAssetService::class)->forCountry('sv');

        $this->assertCount(1, $result['newArrivals']['mobileExtra']);
        $this->assertNull($result['newArrivals']['mobileExtra'][0]['image']);
        $this->assertStringEndsWith('/assets/mobile-extra.jpg', $result['newArrivals']['mobileExtra'][0]['mobileImage']);
        $this->assertSame(5, $result['newArrivals']['mobileExtra'][0]['order']);
        $this->assertSame([], $result['newArrivals']['left']);
    }

    public function test_modal_assets_are_exposed_in_slider_order(): void
    {
        file_put_contents($this->path, json_encode([
            'countries' => ['sv' => ['assets' => ['modal' => [
                ['id' => 22, 'order' => 2, 'image' => '/assets/modal-2.jpg', 'mobileImage' => null, 'link' => null],
                ['id' => 21, 'order' => 1, 'image' => '/assets/modal-1.jpg', 'mobileImage' => '/assets/modal-1-mobile.jpg', 'link' => '/oferta'],
            ]]]],
        ], JSON_THROW_ON_ERROR));

        $result = app(StorefrontAssetService::class)->forCountry('sv');

        $this->assertSame([21, 22], array_column($result['modals'], 'id'));
        $this->assertStringEndsWith('/assets/modal-1-mobile.jpg', $result['modals'][0]['mobileImage']);
        $this->assertSame('/oferta', $result['modals'][0]['href']);
    }
}
