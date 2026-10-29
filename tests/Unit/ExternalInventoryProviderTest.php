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
            'inventory.external.hn_detail_url' => 'https://bihoral.test/api/hn/ec/detalle',
            'inventory.external.hn_categories_url' => 'https://bihoral.test/api/hn/ec/categorias',
            'inventory.external.ve_detail_url' => 'https://bihoral.test/api/ve/ec/detalle',
            'inventory.external.ve_categories_url' => 'https://bihoral.test/api/ve/ec/categorias',
            'inventory.external.generic_detail_url' => 'https://generic.test/api/detalle',
            'inventory.external.generic_categories_url' => 'https://generic.test/api/categorias',
        ]);

    }

    public function test_detail_response_uses_the_requested_canonical_store_code(): void
    {
        Http::fake([
            'https://corepos.test/api/existencias/detalle' => Http::response([
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

        $result = app(ExternalInventoryProvider::class)
            ->fetchProductDetailAvailability(1, 'sv', ['002'], '3000182503');

        $this->assertTrue($result['ok']);
        $this->assertSame('002', $result['rows'][0]['codTienda']);
        $this->assertSame(2, $result['rows'][0]['existencia']);
    }

    public function test_list_response_uses_the_requested_canonical_store_code(): void
    {
        Http::fake([
            'https://corepos.test/api/existencias/categorias' => Http::response([
                'ok' => true,
                'mensaje' => '',
                'cantidad' => 1,
                'registros' => [
                    'codigos' => '3000182503',
                    'existencia' => [[
                        'estilo' => '3000182503',
                        'talla' => '6X',
                        'existencia' => 2,
                    ]],
                ],
            ]),
        ]);

        $result = app(ExternalInventoryProvider::class)
            ->fetchProductListAvailability(1, 'sv', '002', ['3000182503']);

        $this->assertTrue($result['ok']);
        $this->assertSame('002', $result['rows'][0]['codTienda']);
        $this->assertSame('6X', $result['rows'][0]['talla']);
        $this->assertSame(2, $result['rows'][0]['existencia']);
    }

    public function test_honduras_uses_its_own_detail_endpoint(): void
    {
        Http::fake([
            'https://bihoral.test/api/hn/ec/detalle' => Http::response([
                'ok' => true,
                'registros' => ['existencia' => [[
                    'estilo' => '2080188202',
                    'talla' => '04',
                    'existencia' => 3,
                    'codTienda' => '001',
                ]]],
            ]),
        ]);

        $result = app(ExternalInventoryProvider::class)
            ->fetchProductDetailAvailability(7, 'hn', ['001'], '2080188202');

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['rows'][0]['existencia']);
        Http::assertSent(fn ($request) => $request->url() === 'https://bihoral.test/api/hn/ec/detalle');
    }

    public function test_honduras_uses_its_own_categories_endpoint(): void
    {
        Http::fake([
            'https://bihoral.test/api/hn/ec/categorias' => Http::response([
                'ok' => true,
                'registros' => ['existencia' => [[
                    'estilo' => '2080188202',
                    'talla' => '04',
                    'existencia' => 4,
                ]]],
            ]),
        ]);

        $result = app(ExternalInventoryProvider::class)
            ->fetchProductListAvailability(7, 'HN', '001', ['2080188202']);

        $this->assertTrue($result['ok']);
        $this->assertSame('001', $result['rows'][0]['codTienda']);
        Http::assertSent(fn ($request) => $request->url() === 'https://bihoral.test/api/hn/ec/categorias');
    }

    public function test_venezuela_uses_its_own_inventory_endpoints_and_contracts(): void
    {
        Http::fake([
            'https://bihoral.test/api/ve/ec/categorias' => Http::response([
                'ok' => true,
                'registros' => ['existencia' => [[
                    'estilo' => '2030174801', 'talla' => 'M', 'existencia' => 5, 'codTienda' => '1',
                ]]],
            ]),
            'https://bihoral.test/api/ve/ec/detalle' => Http::response([
                'RESULTADO' => true,
                'datos' => [[
                    'estilo' => '2030174801', 'talla' => 'M', 'existencia' => 5, 'codTienda' => '1',
                ]],
            ]),
        ]);

        $provider = app(ExternalInventoryProvider::class);
        $list = $provider->fetchProductListAvailability(8, 've', '01', ['2030174801']);
        $detail = $provider->fetchProductDetailAvailability(8, 've', ['01'], '2030174801');

        $this->assertTrue($list['ok']);
        $this->assertTrue($detail['ok']);
        $this->assertSame('01', $list['rows'][0]['codTienda']);
        $this->assertSame('01', $detail['rows'][0]['codTienda']);
        Http::assertSent(fn ($request) => $request->url() === 'https://bihoral.test/api/ve/ec/categorias'
            && $request['Pais'] === '8' && $request['Tienda'] === '01');
        Http::assertSent(fn ($request) => $request->url() === 'https://bihoral.test/api/ve/ec/detalle'
            && $request['Pais'] === '8' && $request['Tiendas'] === "'01'");
    }
}
