<?php

namespace Tests\Feature;

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
        DB::table('stj_categorias')->insert(['cat_id' => 5, 'cat_nombre' => 'Niñas', 'cat_marca' => 'ST JACKS']);
        DB::table('stj_sub_categorias')->insert([
            ['sca_id' => 10, 'sca_nombre' => 'Vestidos'],
            ['sca_id' => 20, 'sca_nombre' => 'Blusas'],
        ]);
        DB::table('stj_productos')->insert([
            ['pro_id' => 100, 'pro_codigo' => 'SKU-1', 'pro_nombre' => 'VESTIDO ROJO', 'pro_descripcion' => 'Detalle-producto', 'pro_marca' => 'ST JACKS', 'pro_oc_marca' => null, 'pro_categoria' => 5, 'pro_sub_categoria' => 10, 'pro_tallas' => '4,6'],
            ['pro_id' => 101, 'pro_codigo' => 'SKU-2', 'pro_nombre' => 'VESTIDO AZUL', 'pro_descripcion' => null, 'pro_marca' => 'ST JACKS', 'pro_oc_marca' => null, 'pro_categoria' => 5, 'pro_sub_categoria' => 10, 'pro_tallas' => '8'],
            ['pro_id' => 102, 'pro_codigo' => 'SKU-3', 'pro_nombre' => 'BLUSA', 'pro_descripcion' => null, 'pro_marca' => 'ST JACKS', 'pro_oc_marca' => null, 'pro_categoria' => 5, 'pro_sub_categoria' => 20, 'pro_tallas' => '6'],
        ]);
        foreach ([100, 101, 102] as $productId) {
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
