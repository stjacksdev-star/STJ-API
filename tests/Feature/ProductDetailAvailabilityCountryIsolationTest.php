<?php

namespace Tests\Feature;

use App\Services\Inventory\ExternalInventoryProvider;
use App\Services\Inventory\InventorySourceResolver;
use App\Services\Inventory\LocalInventoryProvider;
use App\Services\ProductDetailAvailabilityService;
use App\Services\StorefrontProductPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ProductDetailAvailabilityCountryIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sv_product_detail_never_returns_honduras_stores(): void
    {
        Schema::create('stj_paises', fn (Blueprint $table) => tap($table->bigInteger('pai_id', true), fn () => $table->string('pai_codigo', 3)));
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->bigInteger('pro_id', true);
            $table->string('pro_codigo');
            $table->string('pro_nombre');
            $table->string('pro_tallas');
            $table->string('pro_estatus');
        });
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->bigInteger('ppa_id', true);
            $table->bigInteger('ppa_pais');
            $table->bigInteger('ppa_producto');
            $table->string('ppa_estado');
        });
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->bigInteger('tie_id', true);
            $table->string('tie_codigo');
            $table->string('tie_nombre');
            $table->bigInteger('tie_pais');
        });
        DB::table('stj_paises')->insert([['pai_id' => 1, 'pai_codigo' => 'SV'], ['pai_id' => 6, 'pai_codigo' => 'HN']]);
        DB::table('stj_productos')->insert(['pro_id' => 10, 'pro_codigo' => 'SKU10', 'pro_nombre' => 'Producto', 'pro_tallas' => 'S', 'pro_estatus' => 'ACTIVO']);
        DB::table('stj_producto_pais')->insert(['ppa_pais' => 1, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO']);
        DB::table('stj_tiendas')->insert([
            ['tie_codigo' => '57', 'tie_nombre' => 'Casa Matriz', 'tie_pais' => 1],
            ['tie_codigo' => '002', 'tie_nombre' => 'Las Cascadas', 'tie_pais' => 1],
            ['tie_codigo' => 'H1', 'tie_nombre' => 'ST JACKS MEGA MALL SAN PEDRO', 'tie_pais' => 6],
        ]);
        config(['inventory.stores_by_country.sv' => ['57', '002', 'H1'], 'inventory.default_store_by_country.sv' => '57', 'inventory.domicilio_store_by_country.sv' => '57']);
        $resolver = Mockery::mock(InventorySourceResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['source' => 'local_inventory', 'fallback_source' => null]);
        $local = Mockery::mock(LocalInventoryProvider::class);
        $local->shouldReceive('fetchProductDetailAvailability')->andReturn(['ok' => true, 'rows' => [
            ['estilo' => 'SKU10', 'talla' => 'S', 'existencia' => 2, 'codTienda' => '57', 'tienda' => 'Domicilio'],
            ['estilo' => 'SKU10', 'talla' => 'S', 'existencia' => 1, 'codTienda' => '002', 'tienda' => 'Las Cascadas'],
            ['estilo' => 'SKU10', 'talla' => 'S', 'existencia' => 3, 'codTienda' => 'H1', 'tienda' => 'ST JACKS MEGA MALL SAN PEDRO'],
        ], 'source' => 'local_inventory']);
        $pricing = Mockery::mock(StorefrontProductPricingService::class);
        $pricing->shouldReceive('resolve')->andReturn(['ok' => true]);
        $service = new ProductDetailAvailabilityService($resolver, Mockery::mock(ExternalInventoryProvider::class), $local, $pricing);
        $result = $service->forCountryAndSlug('sv', 'producto-10', '57');
        $alternatives = $result['sizes'][0]['alternativeStores'];
        $this->assertSame(['002'], array_column($alternatives, 'code'));
        $this->assertSame(['Las Cascadas'], array_column($alternatives, 'name'));
        $this->assertSame(3, $result['sizes'][0]['totalQuantity']);
    }
}
