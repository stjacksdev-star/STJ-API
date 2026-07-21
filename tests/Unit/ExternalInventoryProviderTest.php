<?php

namespace Tests\Unit;

use App\Services\Inventory\ExternalInventoryProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalInventoryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inventory.external.token' => 'test-token',
            'inventory.external.sv_detail_url' => 'https://corepos.test/api/existencias/detalle',
            'inventory.external.sv_categories_url' => 'https://corepos.test/api/existencias/categorias',
        ]);

        Http::fake([
            'https://corepos.test/*' => Http::response([
                'RESULTADO' => true,
                'datos' => [[
                    'estilo' => '3000182503',
                    'talla' => '6X',
                    'existencia' => 2,
                    'codTienda' => '2',
                    'tienda' => 'TIENDA ST JACKS LAS CASCADAS',
                ]],
            ]),
        ]);
    }

    public function test_detail_response_uses_the_requested_canonical_store_code(): void
    {
        $result = app(ExternalInventoryProvider::class)
            ->fetchProductDetailAvailability(1, 'sv', ['002'], '3000182503');

        $this->assertTrue($result['ok']);
        $this->assertSame('002', $result['rows'][0]['codTienda']);
        $this->assertSame(2, $result['rows'][0]['existencia']);
    }

    public function test_list_response_uses_the_requested_canonical_store_code(): void
    {
        $result = app(ExternalInventoryProvider::class)
            ->fetchProductListAvailability(1, 'sv', '002', ['3000182503']);

        $this->assertTrue($result['ok']);
        $this->assertSame('002', $result['rows'][0]['codTienda']);
    }
}
