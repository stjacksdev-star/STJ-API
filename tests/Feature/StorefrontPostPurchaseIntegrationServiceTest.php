<?php

namespace Tests\Feature;

use App\Services\StorefrontPostPurchaseIntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontPostPurchaseIntegrationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
            $table->string('pai_codigo', 2);
        });
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->id('ped_id');
            $table->unsignedBigInteger('ped_id_pais');
            $table->string('ped_tienda');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pedido');
            $table->string('ppa_ref');
            $table->string('ppa_estado');
        });
        Schema::create('stj_pedidos_detalle', function (Blueprint $table) {
            $table->id('car_id');
            $table->string('car_ref');
            $table->string('car_accion');
            $table->string('car_estilo_final');
            $table->string('car_talla_final')->nullable();
            $table->string('car_talla');
            $table->integer('car_cantidad');
        });
    }

    public function test_guatemala_reserves_each_item_only_when_enabled(): void
    {
        $this->seedOrder('GT');
        DB::table('stj_pedidos_detalle')->insert([
            'car_ref' => 'WEB-100', 'car_accion' => 'AGREGADO', 'car_estilo_final' => '20001234',
            'car_talla_final' => '4/5', 'car_talla' => '4/5', 'car_cantidad' => 2,
        ]);
        config([
            'storefront_post_purchase.integrations_enabled' => true,
            'storefront_post_purchase.pos_reservation.countries.GT' => true,
            'storefront_post_purchase.pos_reservation.url' => 'https://pos.example/reserva/{store}/{sku}-{size}/{reference}/{quantity}/{country}',
        ]);
        Http::fake(['*' => Http::response([], 200)]);

        app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);

        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example/reserva/002/20001234-4%2F5/WEB-100/2/GT');
    }

    public function test_costa_rica_and_panama_use_the_country_suffix(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        foreach (['CR', 'PA'] as $country) {
            DB::table('stj_pedidos_detalle')->delete();
            DB::table('stj_pedidos_pago')->delete();
            DB::table('stj_pedidos')->delete();
            DB::table('stj_paises')->delete();
            $this->seedOrder($country);
            DB::table('stj_pedidos_detalle')->insert(['car_ref' => 'WEB-100', 'car_accion' => 'AGREGADO', 'car_estilo_final' => 'SKU1', 'car_talla_final' => 'M', 'car_talla' => 'M', 'car_cantidad' => 1]);
            config(['storefront_post_purchase.integrations_enabled' => true, "storefront_post_purchase.pos_reservation.countries.{$country}" => true, 'storefront_post_purchase.pos_reservation.url' => 'https://pos.example/reserva/{store}/{sku}-{size}/{reference}/{quantity}/{country}']);
            app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);
        }

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/1/CR'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/1/PA'));
    }

    public function test_honduras_uses_configured_prism_url_and_ids(): void
    {
        $this->seedOrder('HN');
        config([
            'storefront_post_purchase.integrations_enabled' => true,
            'storefront_post_purchase.honduras.enabled' => true,
            'storefront_post_purchase.honduras.url' => 'https://prism.example/create',
        ]);
        Http::fake(['*' => Http::response([], 200)]);

        app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);

        Http::assertSent(fn ($request) => $request->url() === 'https://prism.example/create?ped_id=10&ppa_id=20');
    }

    public function test_master_switch_prevents_external_requests(): void
    {
        config(['storefront_post_purchase.integrations_enabled' => false]);
        Http::fake();

        app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);

        Http::assertNothingSent();
    }

    private function seedOrder(string $country): void
    {
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => $country]);
        DB::table('stj_pedidos')->insert(['ped_id' => 10, 'ped_id_pais' => 1, 'ped_tienda' => '002']);
        DB::table('stj_pedidos_pago')->insert(['ppa_id' => 20, 'ppa_pedido' => 10, 'ppa_ref' => 'WEB-100', 'ppa_estado' => 'APROBADA']);
    }
}
