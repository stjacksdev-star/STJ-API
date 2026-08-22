<?php

namespace Tests\Feature;

use App\Services\ProductDetailAvailabilityService;
use App\Services\ProductListAvailabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

class MobileProductEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', fn (Blueprint $table) => tap($table->bigInteger('pai_id')->primary(), fn () => $table->string('pai_codigo')));
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->id('tie_id');
            $table->bigInteger('tie_pais');
            $table->string('tie_codigo');
        });
        Schema::create('stj_categorias', function (Blueprint $table) {
            $table->id('cat_id');
            $table->string('cat_nombre');
            $table->boolean('cat_si_sub_otras')->default(false);
            $table->string('cat_sub_otras')->nullable();
            $table->string('cat_marca')->nullable();
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
            $table->string('pro_oc_marca')->nullable();
            $table->unsignedBigInteger('pro_categoria');
            $table->unsignedBigInteger('pro_sub_categoria');
            $table->string('pro_tallas')->nullable();
        });
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pais');
            $table->unsignedBigInteger('ppa_producto');
            $table->string('ppa_estado');
            $table->decimal('ppa_precio');
            $table->decimal('ppa_descuento')->nullable();
            $table->string('ppa_origen_descuento')->nullable();
            $table->string('ppa_promo_nombre')->nullable();
            $table->string('ppa_promo_logo')->nullable();
            $table->string('ppa_tipo_descuento')->nullable();
            $table->decimal('ppa_precio_tienda')->nullable();
        });

        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV'],
            ['pai_id' => 2, 'pai_codigo' => 'GT'],
        ]);
        DB::table('stj_tiendas')->insert([
            ['tie_id' => 1, 'tie_pais' => 1, 'tie_codigo' => '019'],
            ['tie_id' => 2, 'tie_pais' => 2, 'tie_codigo' => '019'],
        ]);
        DB::table('stj_categorias')->insert([
            ['cat_id' => 5, 'cat_nombre' => 'Niñas', 'cat_marca' => 'ST JACKS', 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
            ['cat_id' => 15, 'cat_nombre' => 'Jack & Co', 'cat_marca' => 'JACK & CO', 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
            ['cat_id' => 12, 'cat_nombre' => 'Basikos', 'cat_marca' => 'BASICS', 'cat_si_sub_otras' => 1, 'cat_sub_otras' => '10,20'],
            ['cat_id' => 99, 'cat_nombre' => 'Origen Basikos', 'cat_marca' => 'BASICS', 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
        ]);
        DB::table('stj_sub_categorias')->insert([
            ['sca_id' => 10, 'sca_nombre' => 'Vestidos'],
            ['sca_id' => 20, 'sca_nombre' => 'Blusas'],
        ]);
        DB::table('stj_productos')->insert([
            ['pro_id' => 100, 'pro_codigo' => 'SKU-1', 'pro_nombre' => 'VESTIDO ROJO', 'pro_descripcion' => 'Detalle-producto', 'pro_marca' => 'ST JACKS', 'pro_oc_marca' => null, 'pro_categoria' => 5, 'pro_sub_categoria' => 10, 'pro_tallas' => '4,6'],
            ['pro_id' => 101, 'pro_codigo' => 'SKU-2', 'pro_nombre' => 'VESTIDO AZUL', 'pro_descripcion' => null, 'pro_marca' => 'ST JACKS', 'pro_oc_marca' => null, 'pro_categoria' => 5, 'pro_sub_categoria' => 10, 'pro_tallas' => '8'],
            ['pro_id' => 102, 'pro_codigo' => 'SKU-3', 'pro_nombre' => 'BLUSA', 'pro_descripcion' => null, 'pro_marca' => 'ST JACKS', 'pro_oc_marca' => null, 'pro_categoria' => 5, 'pro_sub_categoria' => 20, 'pro_tallas' => '6'],
            ['pro_id' => 200, 'pro_codigo' => 'JACK-1', 'pro_nombre' => 'CAMISA JACK', 'pro_descripcion' => null, 'pro_marca' => 'JACK & CO', 'pro_oc_marca' => 'JACK & CO', 'pro_categoria' => 15, 'pro_sub_categoria' => 20, 'pro_tallas' => 'S,M'],
            ['pro_id' => 300, 'pro_codigo' => 'BAS-1', 'pro_nombre' => 'CAMISETA BASIKA', 'pro_descripcion' => null, 'pro_marca' => 'BASICS', 'pro_oc_marca' => 'BASICS', 'pro_categoria' => 99, 'pro_sub_categoria' => 10, 'pro_tallas' => '6,8'],
        ]);
        foreach ([100, 101, 102, 200, 300] as $productId) {
            DB::table('stj_producto_pais')->insert([
                'ppa_pais' => 1, 'ppa_producto' => $productId, 'ppa_estado' => 'ACTIVO',
                'ppa_precio' => 20, 'ppa_descuento' => 10, 'ppa_origen_descuento' => 'WEB',
                'ppa_promo_nombre' => 'Oferta', 'ppa_promo_logo' => null,
                'ppa_tipo_descuento' => null, 'ppa_precio_tienda' => null,
            ]);
        }

        config(['mobile.legacy_product_image_url' => 'https://assets.example/p400']);
    }

    public function test_it_filters_category_products_by_the_selected_store_inventory(): void
    {
        $this->mock(ProductListAvailabilityService::class, function (MockInterface $mock) {
            $mock->shouldReceive('summarize')
                ->once()
                ->with('sv', \Mockery::on(fn (array $products) => collect($products)->pluck('pro_codigo')->all() === ['SKU-2', 'SKU-1']), '019')
                ->andReturn([
                    'availabilityBySku' => [
                        'SKU-1' => ['hasStock' => true, 'availableSizes' => ['4', '6'], 'totalQuantity' => 3],
                    ],
                    'availabilityRows' => [
                        ['estilo' => 'SKU-1', 'talla' => '4', 'existencia' => 1],
                        ['estilo' => 'SKU-1', 'talla' => '6', 'existencia' => 2],
                    ],
                ]);
        });

        $this->postJson('/api/mobile/v1/catalog/products/filter?countryId=1', [
            'categoria' => 5,
            'scat' => 10,
            'ordenamiento' => 'Más recientes',
            'min' => '',
            'max' => '',
            'talla' => '',
            'tienda' => '019',
        ])->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.sku', 'SKU-1')
            ->assertJsonPath('records.0.precio', '20.00')
            ->assertJsonPath('records.0.precioCD', '18.00')
            ->assertJsonPath('records.0.availableSizes', ['4', '6'])
            ->assertJsonPath('existenciaTalla.1.existencia', 2);
    }

    public function test_it_returns_products_for_a_category_using_the_selected_store(): void
    {
        $this->mock(ProductListAvailabilityService::class, function (MockInterface $mock) {
            $mock->shouldReceive('summarize')
                ->once()
                ->with('sv', \Mockery::on(fn (array $products) => collect($products)->pluck('pro_codigo')->all() === ['SKU-3', 'SKU-2', 'SKU-1']), '019')
                ->andReturn([
                    'availabilityBySku' => [
                        'SKU-3' => ['hasStock' => true, 'availableSizes' => ['6'], 'totalQuantity' => 2],
                        'SKU-1' => ['hasStock' => true, 'availableSizes' => ['4'], 'totalQuantity' => 1],
                    ],
                ]);
        });

        $this->getJson('/api/mobile/v1/catalog/products?countryId=1&codigoTienda=019&categoryId=5')
            ->assertOk()
            ->assertJsonCount(2, 'records')
            ->assertJsonPath('records.0.sku', 'SKU-3')
            ->assertJsonPath('records.1.sku', 'SKU-1')
            ->assertJsonPath('records.1.availableSizes', ['4']);
    }

    public function test_it_returns_the_direct_legacy_product_detail_contract(): void
    {
        $this->getJson('/api/mobile/v1/catalog/products/100?countryId=1&codigoTienda=019')
            ->assertOk()
            ->assertExactJson([
                'id' => 100,
                'nombre' => 'Vestido Rojo',
                'preciov2' => '20.00',
                'descripcion' => 'Detalle<br/>-producto',
                'Domicilio' => true,
                'Tienda' => true,
            ]);
    }

    public function test_product_detail_isolated_by_country_and_selected_store(): void
    {
        $this->getJson('/api/mobile/v1/catalog/products/100?countryId=2&codigoTienda=019')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product');

        DB::table('stj_tiendas')->where('tie_pais', 1)->delete();
        $this->getJson('/api/mobile/v1/catalog/products/100?countryId=1&codigoTienda=019')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigoTienda');
    }

    public function test_product_detail_requires_country_and_store(): void
    {
        $this->getJson('/api/mobile/v1/catalog/products/100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['countryId', 'codigoTienda']);
    }

    public function test_it_returns_selected_store_sizes_and_other_store_availability(): void
    {
        $this->mock(ProductDetailAvailabilityService::class, function (MockInterface $mock) {
            $mock->shouldReceive('forCountryAndSlug')
                ->once()
                ->with('sv', 'mobile-product-100', '019', 'product_detail')
                ->andReturn([
                    'activeStore' => ['code' => '019', 'name' => 'Ahuachapán'],
                    'sizes' => [
                        [
                            'size' => '4', 'quantityInActiveStore' => 0, 'availableInActiveStore' => false,
                            'alternativeStores' => [['code' => '57', 'name' => 'Domicilio', 'quantity' => 3]],
                        ],
                        [
                            'size' => '6', 'quantityInActiveStore' => 2, 'availableInActiveStore' => true,
                            'alternativeStores' => [['code' => '002', 'name' => 'Las Cascadas', 'quantity' => 7]],
                        ],
                        [
                            'size' => '8', 'quantityInActiveStore' => 0, 'availableInActiveStore' => false,
                            'alternativeStores' => [],
                        ],
                    ],
                ]);
        });

        $this->getJson('/api/mobile/v1/catalog/products/SKU-1/availability?countryId=1&codigoTienda=019')
            ->assertOk()
            ->assertExactJson([
                'records' => [['talla' => '6']],
                'records2' => [['talla' => '4'], ['talla' => '6'], ['talla' => '8']],
                'disp' => '<div class="tabs"><div class="tab"><div class="content"><table class="tbDisp" style="width:90%;margin:1em auto 2em;font-size:0.9em;"><thead><tr><td>Tienda</td><td>4</td><td>6</td><td>8</td></tr></thead><tbody><tr style="background-color:yellow;"><td>Ahuachapán</td><td>0</td><td>2</td><td>0</td></tr><tr><td>Domicilio</td><td>3</td><td>0</td><td>0</td></tr><tr><td>Las Cascadas</td><td>0</td><td>4+</td><td>0</td></tr></tbody></table></div></div></div>',
            ]);
    }

    public function test_product_availability_validates_sku_country_and_store(): void
    {
        $this->getJson('/api/mobile/v1/catalog/products/UNKNOWN/availability?countryId=1&codigoTienda=019')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');

        DB::table('stj_tiendas')->where('tie_pais', 1)->delete();
        $this->getJson('/api/mobile/v1/catalog/products/SKU-1/availability?countryId=1&codigoTienda=019')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigoTienda');
    }

    public function test_category_products_validate_the_selected_store_and_required_parameters(): void
    {
        $this->getJson('/api/mobile/v1/catalog/products')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['countryId', 'categoryId', 'codigoTienda']);

        DB::table('stj_tiendas')->where('tie_pais', 1)->delete();
        $this->getJson('/api/mobile/v1/catalog/products?countryId=1&codigoTienda=019&categoryId=5')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigoTienda');
    }

    public function test_it_filters_jack_co_products_with_the_mobile_contract_and_store_inventory(): void
    {
        $this->mock(ProductListAvailabilityService::class, function (MockInterface $mock) {
            $mock->shouldReceive('summarize')
                ->once()
                ->with('sv', [['pro_codigo' => 'JACK-1']], '019')
                ->andReturn([
                    'availabilityBySku' => [
                        'JACK-1' => ['hasStock' => true, 'availableSizes' => ['S'], 'totalQuantity' => 2],
                    ],
                ]);
        });

        $this->postJson('/api/mobile/v1/catalog/products/jack-co/filter?countryId=1&codigoTienda=019', [
            'categoria' => 15,
            'scat' => '',
            'ordenamiento' => 'Más recientes',
            'min' => '',
            'max' => '',
            'talla' => '',
            'pais' => 1,
        ])->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.sku', 'JACK-1')
            ->assertJsonPath('records.0.marca', 'JACK & CO')
            ->assertJsonPath('records.0.sello', 'https://stjacks.com/img/v2/icons/Icon%20awesome-tag.svg')
            ->assertJsonPath('records.0.availableSizes', ['S']);
    }

    public function test_jack_co_filter_requires_a_store_from_the_selected_country(): void
    {
        DB::table('stj_tiendas')->where('tie_pais', 1)->delete();

        $this->postJson('/api/mobile/v1/catalog/products/jack-co/filter?countryId=1&codigoTienda=019', [
            'categoria' => 15,
        ])->assertUnprocessable()->assertJsonValidationErrors('codigoTienda');
    }

    public function test_it_filters_basikos_from_the_dynamic_category_scope_and_selected_store(): void
    {
        $this->mock(ProductListAvailabilityService::class, function (MockInterface $mock) {
            $mock->shouldReceive('summarize')
                ->once()
                ->with('sv', [['pro_codigo' => 'BAS-1']], '019')
                ->andReturn([
                    'availabilityBySku' => [
                        'BAS-1' => ['hasStock' => true, 'availableSizes' => ['6'], 'totalQuantity' => 1],
                    ],
                ]);
        });

        $this->postJson('/api/mobile/v1/catalog/products/basikos/filter?countryId=1&codigoTienda=019', [
            'categoria' => 12,
            'scat' => '',
            'ordenamiento' => 'Más recientes',
            'min' => '',
            'max' => '',
            'talla' => '',
            'pais' => 1,
            'tienda' => '019',
        ])->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.sku', 'BAS-1')
            ->assertJsonPath('records.0.marca', 'BASIKOS')
            ->assertJsonPath('records.0.sello', 'https://stjacks.com/img/v2/icons/Icon%20awesome-tag.svg')
            ->assertJsonPath('records.0.availableSizes', ['6']);
    }

    public function test_basikos_filter_requires_a_store_from_the_selected_country(): void
    {
        DB::table('stj_tiendas')->where('tie_pais', 1)->delete();

        $this->postJson('/api/mobile/v1/catalog/products/basikos/filter?countryId=1&codigoTienda=019', [
            'categoria' => 12,
        ])->assertUnprocessable()->assertJsonValidationErrors('codigoTienda');
    }

    public function test_it_rejects_a_store_that_does_not_belong_to_the_country(): void
    {
        DB::table('stj_tiendas')->where('tie_pais', 1)->delete();

        $this->postJson('/api/mobile/v1/catalog/products/filter?countryId=1', [
            'categoria' => 5,
            'tienda' => '019',
        ])->assertUnprocessable()->assertJsonValidationErrors('tienda');
    }

    public function test_it_requires_country_category_and_store(): void
    {
        $this->postJson('/api/mobile/v1/catalog/products/filter', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['countryId', 'categoria', 'tienda']);
    }
}
