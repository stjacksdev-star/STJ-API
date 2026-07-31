<?php

namespace Tests\Feature;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontRecommendationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['stj_pedidos_detalle', 'stj_pedidos_pago', 'stj_producto_talla', 'stj_inventario', 'stj_producto_pais', 'stj_productos', 'stj_categorias', 'stj_promociones', 'stj_pedidos', 'stj_usuarios', 'stj_paises', 'stj_tiendas'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('stj_paises', function (Blueprint $t) {
            $t->id('pai_id');
            $t->string('pai_codigo', 3);
        });
        Schema::create('stj_usuarios', function (Blueprint $t) {
            $t->id('usu_id');
        });
        Schema::create('stj_pedidos', function (Blueprint $t) {
            $t->id('ped_id');
            $t->unsignedBigInteger('ped_id_pais');
            $t->unsignedBigInteger('ped_user')->nullable();
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $t) {
            $t->id('ppa_id');
            $t->unsignedBigInteger('ppa_pedido');
            $t->string('ppa_ref');
            $t->string('ppa_estado');
            $t->dateTime('ppa_fecha');
        });
        Schema::create('stj_pedidos_detalle', function (Blueprint $t) {
            $t->id('car_id');
            $t->unsignedBigInteger('car_pais');
            $t->unsignedBigInteger('car_producto');
            $t->string('car_ref');
            $t->string('car_accion');
        });
        Schema::create('stj_promociones', function (Blueprint $t) {
            $t->id('prm_id');
        });
        Schema::create('stj_categorias', function (Blueprint $t) {
            $t->id('cat_id');
            $t->string('cat_nombre');
        });
        Schema::create('stj_productos', function (Blueprint $t) {
            $t->id('pro_id');
            $t->string('pro_codigo');
            $t->string('pro_nombre');
            $t->unsignedBigInteger('pro_categoria')->nullable();
            $t->string('pro_coleccion')->nullable();
            $t->string('pro_marca')->nullable();
            $t->string('pro_personaje')->nullable();
            $t->string('pro_oc_personaje')->nullable();
            $t->string('pro_oc_licencia')->nullable();
            $t->string('pro_oc_genero')->nullable();
            $t->string('pro_tallas')->default('S');
            $t->string('pro_thumbs')->nullable();
            $t->string('pro_estatus')->default('ACTIVO');
            $t->dateTime('pro_registro')->nullable();
        });
        Schema::create('stj_producto_pais', function (Blueprint $t) {
            $t->id('ppa_id');
            $t->unsignedBigInteger('ppa_pais');
            $t->unsignedBigInteger('ppa_producto');
            $t->string('ppa_estado')->default('ACTIVO');
            $t->decimal('ppa_precio');
            $t->string('ppa_precio_talla')->default('NO');
            $t->decimal('ppa_descuento')->nullable();
            $t->boolean('ppa_es_popular')->default(false);
        });
        Schema::create('stj_producto_talla', function (Blueprint $t) {
            $t->id('pta_id');
            $t->unsignedBigInteger('pta_pais');
            $t->unsignedBigInteger('pta_producto');
            $t->string('pta_talla');
            $t->decimal('pta_precio');
        });
        Schema::create('stj_inventario', function (Blueprint $t) {
            $t->id('inv_id');
            $t->unsignedBigInteger('inv_pais');
            $t->string('inv_tienda');
            $t->string('inv_codigo');
            $t->string('inv_talla');
            $t->integer('inv_cantidad');
        });
        Schema::create('stj_tiendas', function (Blueprint $t) {
            $t->id('tie_id');
            $t->unsignedBigInteger('tie_pais');
            $t->string('tie_codigo');
            $t->string('tie_nombre');
        });
        DB::table('stj_paises')->insert([['pai_id' => 1, 'pai_codigo' => 'SV'], ['pai_id' => 2, 'pai_codigo' => 'HN']]);
        DB::table('stj_usuarios')->insert(['usu_id' => 99]);
        DB::table('stj_categorias')->insert(['cat_id' => 1, 'cat_nombre' => 'Camisas']);
        DB::table('stj_productos')->insert([['pro_id' => 10, 'pro_codigo' => 'A', 'pro_nombre' => 'A', 'pro_categoria' => 1, 'pro_coleccion' => 'C1', 'pro_marca' => 'ST JACKS', 'pro_personaje' => 'SNOOPY', 'pro_oc_personaje' => 'SNOOPY', 'pro_oc_licencia' => 'PEANUTS', 'pro_oc_genero' => 'NINA', 'pro_tallas' => 'S', 'pro_estatus' => 'ACTIVO'], ['pro_id' => 11, 'pro_codigo' => 'B', 'pro_nombre' => 'B', 'pro_categoria' => 1, 'pro_coleccion' => 'C1', 'pro_marca' => 'ST JACKS', 'pro_personaje' => 'SNOOPY', 'pro_oc_personaje' => 'SNOOPY', 'pro_oc_licencia' => 'PEANUTS', 'pro_oc_genero' => 'NINA', 'pro_tallas' => 'S', 'pro_estatus' => 'ACTIVO']]);
        DB::table('stj_producto_pais')->insert([['ppa_pais' => 1, 'ppa_producto' => 10, 'ppa_precio' => 10], ['ppa_pais' => 1, 'ppa_producto' => 11, 'ppa_precio' => 12]]);
        DB::table('stj_inventario')->insert([['inv_pais' => 1, 'inv_tienda' => '57', 'inv_codigo' => 'A', 'inv_talla' => 'S', 'inv_cantidad' => 2], ['inv_pais' => 1, 'inv_tienda' => '57', 'inv_codigo' => 'B', 'inv_talla' => 'S', 'inv_cantidad' => 2]]);
        DB::table('stj_tiendas')->insert(['tie_id' => 1, 'tie_pais' => 1, 'tie_codigo' => '57', 'tie_nombre' => 'Domicilio']);
    }

    public function test_guest_recent_views_are_private_country_scoped_and_deduplicated(): void
    {
        $visitor = DB::table('stj_visitantes')->insertGetId(['vis_uuid' => '11111111-1111-4111-8111-111111111111', 'vis_origen' => 'WEB', 'vis_primera_visita' => now(), 'vis_ultima_visita' => now(), 'vis_expira_en' => now()->addYear(), 'vis_creado_en' => now(), 'vis_actualizado_en' => now()]);
        foreach ([10, 10, 11] as $index => $product) {
            DB::table('stj_cliente_eventos')->insert(['cev_event_uuid' => sprintf('22222222-2222-4222-8222-%012d', $index), 'cev_visitante_id' => $visitor, 'cev_usu_id' => null, 'cev_pais_id' => 1, 'cev_producto_id' => $product, 'cev_tipo' => 'PRODUCT_VIEW', 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now()->addSeconds($index), 'cev_recibido_en' => now()]);
        }
        DB::table('stj_cliente_eventos')->insert(['cev_event_uuid' => '33333333-3333-4333-8333-333333333333', 'cev_visitante_id' => $visitor, 'cev_usu_id' => 99, 'cev_pais_id' => 1, 'cev_producto_id' => 10, 'cev_tipo' => 'PRODUCT_VIEW', 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now()->addMinute(), 'cev_recibido_en' => now()]);

        $products = app(StorefrontRecommendationService::class)->recommend('sv', 'RECENTLY_VIEWED', StorefrontVisitor::findOrFail($visitor));
        $this->assertCount(2, $products);
        $this->assertSame([11, 10], array_column($products, 'product_id'));
    }

    public function test_authenticated_cart_recommendations_use_approved_orders_by_customer_id_and_country(): void
    {
        $visitor = DB::table('stj_visitantes')->insertGetId(['vis_uuid' => '44444444-4444-4444-8444-444444444444', 'vis_origen' => 'WEB', 'vis_primera_visita' => now(), 'vis_ultima_visita' => now(), 'vis_expira_en' => now()->addYear(), 'vis_creado_en' => now(), 'vis_actualizado_en' => now()]);
        DB::table('stj_pedidos')->insert(['ped_id' => 100, 'ped_id_pais' => 1, 'ped_user' => 99]);
        DB::table('stj_pedidos_pago')->insert(['ppa_id' => 1, 'ppa_pedido' => 100, 'ppa_ref' => 'APPROVED-100', 'ppa_estado' => 'APROBADA', 'ppa_fecha' => now()]);
        DB::table('stj_pedidos_detalle')->insert(['car_pais' => 1, 'car_producto' => 10, 'car_ref' => 'APPROVED-100', 'car_accion' => 'AGREGADO']);

        $products = app(StorefrontRecommendationService::class)->recommend('sv', 'CART_RECOMMENDATIONS', StorefrontVisitor::findOrFail($visitor), StorefrontCustomer::findOrFail(99), null, 1);

        $this->assertSame(11, $products[0]['product_id']);
        $this->assertSame('purchase_history', $products[0]['recommendation_source']);
        $this->assertSame('Recomendado para ti', $products[0]['badge']);
    }

    public function test_approved_orders_from_another_country_do_not_personalize_recommendations(): void
    {
        DB::table('stj_usuarios')->insert(['usu_id' => 100]);
        $visitor = DB::table('stj_visitantes')->insertGetId(['vis_uuid' => '55555555-5555-4555-8555-555555555555', 'vis_origen' => 'WEB', 'vis_primera_visita' => now(), 'vis_ultima_visita' => now(), 'vis_expira_en' => now()->addYear(), 'vis_creado_en' => now(), 'vis_actualizado_en' => now()]);
        DB::table('stj_pedidos')->insert(['ped_id' => 101, 'ped_id_pais' => 2, 'ped_user' => 100]);
        DB::table('stj_pedidos_pago')->insert(['ppa_id' => 2, 'ppa_pedido' => 101, 'ppa_ref' => 'HN-101', 'ppa_estado' => 'APROBADA', 'ppa_fecha' => now()]);
        DB::table('stj_pedidos_detalle')->insert(['car_pais' => 2, 'car_producto' => 10, 'car_ref' => 'HN-101', 'car_accion' => 'AGREGADO']);

        $products = app(StorefrontRecommendationService::class)->recommend('sv', 'CART_RECOMMENDATIONS', StorefrontVisitor::findOrFail($visitor), StorefrontCustomer::findOrFail(100), null, 1);

        $this->assertSame('fallback', $products[0]['recommendation_source']);
    }
}
