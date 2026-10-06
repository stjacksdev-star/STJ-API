<?php

namespace Tests\Feature;

use App\Services\ProductListAvailabilityService;
use App\Services\StorefrontBrandService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontBrandFeaturedRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_featured_uses_thirty_day_sales_metrics_without_product_status(): void
    {
        $this->schema();
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador']);
        DB::table('stj_marcas')->insert(['mar_id' => 1, 'mar_nombre' => 'Jack & Co', 'mar_slug' => 'jackco', 'mar_codigo' => 'JACK & CO', 'mar_estado' => 'ACTIVA']);
        DB::table('stj_categorias')->insert(['cat_id' => 1, 'cat_nombre' => 'Juvenil']);
        DB::table('stj_productos')->insert([
            [
                'pro_id' => 10,
                'pro_codigo' => 'SKU10',
                'pro_nombre' => 'Producto más vendido disponible',
                'pro_marca' => 'JACK & CO',
                'pro_categoria' => 1,
                'pro_tallas' => 'S,M',
                'pro_estatus' => 'INACTIVO',
                'pro_registro' => now(),
            ],
            [
                'pro_id' => 11,
                'pro_codigo' => 'SKU11',
                'pro_nombre' => 'Producto sin existencia',
                'pro_marca' => 'JACK & CO',
                'pro_categoria' => 1,
                'pro_tallas' => 'S,M',
                'pro_estatus' => 'INACTIVO',
                'pro_registro' => now(),
            ],
        ]);
        DB::table('stj_producto_pais')->insert([
            ['ppa_producto' => 10, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 20, 'ppa_es_popular' => 0],
            ['ppa_producto' => 11, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 30, 'ppa_es_popular' => 0],
        ]);
        DB::table('stj_producto_metricas')->insert([
            ['pme_producto' => 10, 'pme_pais' => 1, 'pme_periodo' => '30D', 'pme_ventas_unidades' => 15, 'pme_ranking_ventas' => 1],
            ['pme_producto' => 11, 'pme_pais' => 1, 'pme_periodo' => '30D', 'pme_ventas_unidades' => 10, 'pme_ranking_ventas' => 2],
        ]);

        $availability = $this->mock(ProductListAvailabilityService::class);
        $availability->shouldReceive('summarize')->andReturn([
            'availabilityBySku' => [
                'SKU10' => [
                    'availableSizes' => ['M'],
                    'hasStock' => true,
                    'totalQuantity' => 1,
                ],
            ],
        ]);
        $payload = (new StorefrontBrandService($availability))->show('sv', 'jackco');

        $this->assertSame([10, 11], array_column($payload['featured'], 'id'));
        $this->assertSame('#1', $payload['featured'][0]['badge']);
        $this->assertTrue($payload['featured'][0]['hasStock']);
        $this->assertFalse($payload['featured'][1]['hasStock']);
        $this->assertSame([], $payload['newArrivals']);
    }

    private function schema(): void
    {
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });
        Schema::create('stj_marcas', function (Blueprint $table) {
            $table->id('mar_id');
            $table->string('mar_nombre');
            $table->string('mar_slug');
            $table->string('mar_codigo');
            $table->string('mar_estado');
        });
        Schema::create('stj_categorias', function (Blueprint $table) {
            $table->id('cat_id');
            $table->string('cat_nombre');
            $table->integer('cat_orden')->nullable();
            $table->string('cat_header')->nullable();
            $table->string('cat_logo_app')->nullable();
        });
        Schema::create('stj_sub_categorias', function (Blueprint $table) {
            $table->id('sca_id');
            $table->string('sca_nombre')->nullable();
        });
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->id('pro_id');
            $table->string('pro_codigo');
            $table->string('pro_nombre');
            $table->string('pro_marca')->nullable();
            $table->unsignedBigInteger('pro_categoria')->nullable();
            $table->unsignedBigInteger('pro_sub_categoria')->nullable();
            $table->string('pro_oc_categoria')->nullable();
            $table->string('pro_tallas')->nullable();
            $table->string('pro_estatus');
            $table->text('pro_descripcion')->nullable();
            $table->string('pro_thumbs')->nullable();
            $table->dateTime('pro_registro');
        });
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->unsignedBigInteger('ppa_producto');
            $table->unsignedBigInteger('ppa_pais');
            $table->string('ppa_estado');
            $table->decimal('ppa_precio', 12, 2);
            $table->string('ppa_promo_nombre')->nullable();
            $table->boolean('ppa_es_popular')->default(false);
        });
        Schema::create('stj_producto_metricas', function (Blueprint $table) {
            $table->unsignedBigInteger('pme_producto');
            $table->unsignedBigInteger('pme_pais');
            $table->string('pme_periodo');
            $table->integer('pme_ventas_unidades');
            $table->integer('pme_ranking_ventas');
        });
    }
}
