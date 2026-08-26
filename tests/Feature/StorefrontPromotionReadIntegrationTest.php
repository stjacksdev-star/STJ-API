<?php

namespace Tests\Feature;

use App\Services\ProductListAvailabilityService;
use App\Services\StorefrontProductService;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontPromotionLandingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class StorefrontPromotionReadIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        config()->set('promotions.timezone', 'America/El_Salvador');
        $this->createSchema();
        $this->seedData();
    }

    public function test_promotion_landing_uses_resolver_instead_of_global_product_country_fields(): void
    {
        $availability = Mockery::mock(ProductListAvailabilityService::class);
        $availability->shouldReceive('summarize')->once()->andReturn([
            'availabilityBySku' => [],
            'activeStoreCode' => null,
            'usedSource' => null,
        ]);
        $this->app->instance(ProductListAvailabilityService::class, $availability);

        $result = app(StorefrontPromotionLandingService::class)->find('SV', 10, [
            'page' => 1,
            'perPage' => 24,
            'checkoutType' => 'DOMICILIO',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(10, $result['products'][0]['promotion']['id']);
        $this->assertSame('Promoción válida para compras a domicilio y en todas nuestras tiendas', $result['promotion']['scope']['headline']);
        $this->assertSame(75.0, $result['products'][0]['price']);
        $this->assertSame(100.0, $result['products'][0]['previousPrice']);
        $this->assertSame(25.0, $result['products'][0]['discountPercentage']);
        $this->assertSame('25% de descuento', $result['products'][0]['badge']);
        $this->assertNotSame('GLOBAL INCORRECTO', $result['products'][0]['promoName']);
    }

    public function test_catalog_ignores_stale_product_country_promotion_labels(): void
    {
        DB::table('stj_producto_pais')
            ->where('ppa_producto', 100)
            ->where('ppa_pais', 1)
            ->update(['ppa_precio' => 22.95]);
        DB::table('stj_promociones')->where('prm_id', 10)->update(['prm_estado' => 'FINALIZADA']);
        DB::table('stj_promociones_horario')->where('pho_promocion', 10)->update([
            'pho_fin' => now()->subMinute(),
            'pho_estado' => 'FINALIZADO',
        ]);

        $availability = Mockery::mock(ProductListAvailabilityService::class);
        $availability->shouldReceive('summarize')->once()->andReturn([
            'availabilityBySku' => [],
            'activeStoreCode' => null,
            'usedSource' => null,
        ]);
        $this->app->instance(ProductListAvailabilityService::class, $availability);

        $result = app(StorefrontCatalogService::class)->forCountry('SV');
        $product = collect($result['products'])->firstWhere('id', 100);

        $this->assertNotNull($product);
        $this->assertSame(22.95, $product['price']);
        $this->assertNull($product['previousPrice']);
        $this->assertNull($product['promotion']);
        $this->assertSame('', $product['promoName']);
        $this->assertNotSame('GLOBAL INCORRECTO', $product['badge']);
    }

    public function test_country_wide_fixed_percentage_landing_does_not_order_by_the_percentage_as_a_column(): void
    {
        DB::table('stj_promociones')->insert([
            'prm_id' => 11,
            'prm_pais' => 1,
            'prm_origen' => 'WEB',
            'prm_nombre' => 'Promoción general',
            'prm_nombre_comercial' => 'Promoción general',
            'prm_modalidad' => 'PROGRAMADO',
            'prm_tipo' => 'TODO',
            'prm_estado' => 'EN-PROCESO',
            'prm_tipo_promocion' => 'DESCUENTO',
            'prm_restriccion' => null,
            'prm_porcentaje' => 20,
            'prm_precio' => null,
            'prm_tipo_checkout' => 'TODO',
            'prm_alcance_tienda' => 'TODAS',
            'prm_aplica' => 'TODO',
            'prm_encabezado' => null,
        ]);
        DB::table('stj_promociones_horario')->insert([
            'pho_id' => 11,
            'pho_tipo' => 'NORMAL',
            'pho_promocion' => 11,
            'pho_inicio' => now()->subDay(),
            'pho_fin' => now()->addDay(),
            'pho_estado' => 'ACTIVO',
        ]);

        $availability = Mockery::mock(ProductListAvailabilityService::class);
        $availability->shouldReceive('summarize')->once()->andReturn([
            'availabilityBySku' => [],
            'activeStoreCode' => null,
            'usedSource' => null,
        ]);
        $this->app->instance(ProductListAvailabilityService::class, $availability);

        $result = app(StorefrontPromotionLandingService::class)->find('SV', 11, [
            'sort' => 'discount_desc',
            'checkoutType' => 'DOMICILIO',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(80.0, $result['products'][0]['price']);
        $this->assertSame(20.0, $result['products'][0]['discountPercentage']);
    }

    public function test_default_sort_prioritizes_local_stock_for_the_selected_store_before_pagination(): void
    {
        DB::table('stj_tiendas')->insert([
            'tie_id' => 57,
            'tie_pais' => 1,
            'tie_codigo' => '57',
            'tie_nombre' => 'Domicilio',
            'tie_productos' => 0,
        ]);
        DB::table('stj_productos')->insert([
            'pro_id' => 101,
            'pro_codigo' => 'SKU101',
            'pro_nombre' => 'Producto disponible',
            'pro_marca' => 'ST JACKS',
            'pro_oc_genero' => 'NIÑA',
            'pro_estatus' => 'ACTIVO',
            'pro_registro' => '2026-01-01 10:00:00',
        ]);
        DB::table('stj_producto_pais')->insert([
            'ppa_id' => 2,
            'ppa_pais' => 1,
            'ppa_producto' => 101,
            'ppa_estado' => 'ACTIVO',
            'ppa_precio' => 100,
        ]);
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => 10,
            'ppr_producto' => 101,
            'ppr_descuento' => 10,
        ]);
        DB::table('stj_inventario')->insert([
            [
                'inv_pais' => 1,
                'inv_tienda' => '57',
                'inv_codigo' => 'SKU100',
                'inv_talla' => '4',
                'inv_cantidad' => 1,
            ],
            [
                'inv_pais' => 1,
                'inv_tienda' => '57',
                'inv_codigo' => 'SKU101',
                'inv_talla' => '4',
                'inv_cantidad' => 2,
            ],
        ]);

        $availability = Mockery::mock(ProductListAvailabilityService::class);
        $availability->shouldReceive('summarize')
            ->once()
            ->with('sv', Mockery::on(fn (array $products) => $products[0]->pro_codigo === 'SKU101'), '57')
            ->andReturn([
                'availabilityBySku' => [
                    'SKU101' => ['availableSizes' => ['4'], 'hasStock' => true, 'totalQuantity' => 2],
                ],
                'activeStoreCode' => '57',
                'usedSource' => 'local_inventory',
            ]);
        $this->app->instance(ProductListAvailabilityService::class, $availability);

        $result = app(StorefrontPromotionLandingService::class)->find('SV', 10, [
            'page' => 1,
            'perPage' => 1,
            'checkoutType' => 'DOMICILIO',
            'storeCode' => '57',
        ]);

        $this->assertSame('SKU101', $result['products'][0]['sku']);
        $this->assertTrue($result['products'][0]['hasStock']);
        $this->assertSame('featured', $result['filters']['active']['sort']);
        $this->assertSame(2, $result['pagination']['total']);
    }

    public function test_selected_store_landing_exposes_participants_and_context_warnings(): void
    {
        DB::table('stj_promociones')->where('prm_id', 10)->update([
            'prm_tipo_checkout' => 'TODO',
            'prm_alcance_tienda' => 'SELECCIONADAS',
        ]);
        DB::table('stj_tiendas')->insert([
            ['tie_id' => 2, 'tie_pais' => 1, 'tie_codigo' => '018', 'tie_nombre' => 'Las Cascadas', 'tie_direccion' => 'Centro comercial', 'tie_horario' => 'Lunes a domingo'],
            ['tie_id' => 3, 'tie_pais' => 1, 'tie_codigo' => '003', 'tie_nombre' => 'Otra tienda', 'tie_direccion' => null, 'tie_horario' => null],
        ]);
        DB::table('stj_promociones_tienda')->insert(['prt_promocion' => 10, 'prt_tienda' => 2]);

        $availability = Mockery::mock(ProductListAvailabilityService::class);
        $availability->shouldReceive('summarize')->times(3)->andReturn([
            'availabilityBySku' => [], 'activeStoreCode' => null, 'usedSource' => null,
        ]);
        $this->app->instance(ProductListAvailabilityService::class, $availability);
        $service = app(StorefrontPromotionLandingService::class);

        $home = $service->find('SV', 10, ['checkoutType' => 'DOMICILIO']);
        $wrongStore = $service->find('SV', 10, ['checkoutType' => 'TIENDA', 'storeCode' => '003']);
        $rightStore = $service->find('SV', 10, ['checkoutType' => 'TIENDA', 'storeCode' => '018']);

        $this->assertSame('Promoción válida en tiendas seleccionadas', $home['promotion']['scope']['headline']);
        $this->assertSame('Válida en 1 tienda', $home['promotion']['scope']['storeCountLabel']);
        $this->assertSame('Esta promoción está disponible únicamente para compras en tiendas físicas.', $home['promotion']['scope']['contextMessage']);
        $this->assertSame('Esta promoción no aplica en la tienda seleccionada.', $wrongStore['promotion']['scope']['contextMessage']);
        $this->assertTrue($rightStore['promotion']['scope']['eligibleForCurrentContext']);
        $this->assertSame('Las Cascadas', $rightStore['promotion']['scope']['stores'][0]['name']);
    }

    public function test_pdp_uses_resolver_and_returns_structured_promotion(): void
    {
        $result = app(StorefrontProductService::class)->forCountryAndSlug(
            'SV',
            'producto-prueba-100',
            ['checkoutType' => 'DOMICILIO'],
        );

        $this->assertNotNull($result);
        $this->assertSame(75.0, $result['product']['price']);
        $this->assertSame(100.0, $result['product']['previousPrice']);
        $this->assertSame(25.0, $result['product']['discountPercentage']);
        $this->assertSame('25% de descuento', $result['product']['badge']);
        $this->assertSame(10, $result['product']['promotion']['id']);
        $this->assertSame('25% de descuento', $result['product']['promotion']['benefitLabel']);
    }

    private function seedData(): void
    {
        DB::table('stj_paises')->insert([
            'pai_id' => 1,
            'pai_codigo' => 'SV',
        ]);
        DB::table('stj_categorias')->insert([
            'cat_id' => 1,
            'cat_nombre' => 'Pijamas',
        ]);
        DB::table('stj_productos')->insert([
            'pro_id' => 100,
            'pro_codigo' => 'SKU100',
            'pro_nombre' => 'Producto prueba',
            'pro_descripcion' => 'Descripción',
            'pro_marca' => 'ST JACKS',
            'pro_oc_genero' => 'NIÑA',
            'pro_oc_categoria' => 'Pijamas',
            'pro_oc_coleccion' => '',
            'pro_personaje' => '',
            'pro_tags' => '',
            'pro_tallas' => '4,6',
            'pro_thumbs' => 'producto.jpg',
            'pro_categoria' => 1,
            'pro_sub_categoria' => null,
            'pro_estatus' => 'ACTIVO',
            'pro_registro' => '2026-07-29 10:00:00',
        ]);
        DB::table('stj_producto_pais')->insert([
            'ppa_id' => 1,
            'ppa_pais' => 1,
            'ppa_producto' => 100,
            'ppa_estado' => 'ACTIVO',
            'ppa_precio' => 100,
            'ppa_descuento' => 5,
            'ppa_promo_nombre' => 'GLOBAL INCORRECTO',
            'ppa_es_popular' => 0,
        ]);
        DB::table('stj_promociones')->insert([
            'prm_id' => 10,
            'prm_pais' => 1,
            'prm_origen' => 'WEB',
            'prm_nombre' => 'Promoción resolver',
            'prm_nombre_comercial' => 'Promoción resolver',
            'prm_modalidad' => 'PROGRAMADO',
            'prm_tipo' => 'SKU',
            'prm_estado' => 'EN-PROCESO',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
            'prm_restriccion' => null,
            'prm_porcentaje' => null,
            'prm_precio' => null,
            'prm_tipo_checkout' => 'TODO',
            'prm_alcance_tienda' => 'TODAS',
            'prm_aplica' => 'TODO',
            'prm_encabezado' => null,
        ]);
        DB::table('stj_promociones_horario')->insert([
            'pho_id' => 10,
            'pho_tipo' => 'NORMAL',
            'pho_promocion' => 10,
            'pho_inicio' => now()->subDay(),
            'pho_fin' => now()->addDay(),
            'pho_estado' => 'ACTIVO',
        ]);
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => 10,
            'ppr_producto' => 100,
            'ppr_descuento' => 25,
            'ppr_precio' => null,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
            $table->string('pai_codigo');
        });
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->id('tie_id');
            $table->unsignedBigInteger('tie_pais');
            $table->string('tie_codigo');
            $table->string('tie_nombre');
            $table->string('tie_direccion')->nullable();
            $table->text('tie_horario')->nullable();
            $table->boolean('tie_productos')->default(true);
        });
        Schema::create('stj_categorias', function (Blueprint $table) {
            $table->id('cat_id');
            $table->string('cat_nombre');
        });
        Schema::create('stj_sub_categorias', function (Blueprint $table) {
            $table->id('sca_id');
            $table->string('sca_nombre');
        });
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->id('pro_id');
            $table->string('pro_codigo');
            $table->string('pro_nombre');
            $table->text('pro_descripcion')->nullable();
            $table->string('pro_marca')->nullable();
            $table->string('pro_oc_genero')->nullable();
            $table->string('pro_oc_categoria')->nullable();
            $table->string('pro_oc_coleccion')->nullable();
            $table->string('pro_personaje')->nullable();
            $table->string('pro_tags')->nullable();
            $table->string('pro_tallas')->nullable();
            $table->string('pro_thumbs')->nullable();
            $table->unsignedBigInteger('pro_categoria')->nullable();
            $table->unsignedBigInteger('pro_sub_categoria')->nullable();
            $table->string('pro_estatus');
            $table->dateTime('pro_registro')->nullable();
        });
        Schema::create('stj_productos_fotos', function (Blueprint $table) {
            $table->id('pfo_id');
            $table->unsignedBigInteger('pfo_producto');
            $table->string('pfo_url');
            $table->integer('pfo_orden');
            $table->boolean('pfo_portada');
        });
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pais');
            $table->unsignedBigInteger('ppa_producto');
            $table->string('ppa_estado');
            $table->decimal('ppa_precio');
            $table->decimal('ppa_descuento')->nullable();
            $table->string('ppa_promo_nombre')->nullable();
            $table->boolean('ppa_es_popular')->default(false);
        });
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->unsignedBigInteger('prm_pais');
            $table->string('prm_origen');
            $table->string('prm_nombre');
            $table->string('prm_nombre_comercial')->nullable();
            $table->string('prm_modalidad');
            $table->string('prm_tipo');
            $table->string('prm_estado');
            $table->string('prm_tipo_promocion');
            $table->string('prm_restriccion')->nullable();
            $table->decimal('prm_porcentaje')->nullable();
            $table->decimal('prm_precio')->nullable();
            $table->string('prm_tipo_checkout')->nullable();
            $table->string('prm_alcance_tienda')->nullable();
            $table->string('prm_aplica')->nullable();
            $table->string('prm_encabezado')->nullable();
        });
        Schema::create('stj_promociones_horario', function (Blueprint $table) {
            $table->id('pho_id');
            $table->string('pho_tipo');
            $table->unsignedBigInteger('pho_promocion');
            $table->dateTime('pho_inicio');
            $table->dateTime('pho_fin');
            $table->string('pho_estado');
        });
        Schema::create('stj_promociones_producto', function (Blueprint $table) {
            $table->id('ppr_id');
            $table->unsignedBigInteger('ppr_promocion');
            $table->unsignedBigInteger('ppr_producto');
            $table->decimal('ppr_descuento')->nullable();
            $table->decimal('ppr_precio')->nullable();
        });
        Schema::create('stj_promociones_tienda', function (Blueprint $table) {
            $table->id('prt_id');
            $table->unsignedBigInteger('prt_promocion');
            $table->unsignedBigInteger('prt_tienda');
        });
        Schema::create('stj_inventario', function (Blueprint $table) {
            $table->id('inv_id');
            $table->unsignedBigInteger('inv_pais');
            $table->string('inv_tienda');
            $table->string('inv_codigo');
            $table->string('inv_talla');
            $table->integer('inv_cantidad')->nullable();
        });
    }
}
