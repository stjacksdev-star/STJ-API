<?php

namespace Tests\Feature;

use App\Services\StorefrontOrderConfirmationEmailService;
use App\Services\StorefrontPostPurchaseIntegrationService;
use App\Services\StorefrontPostPurchaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Tests require testing and SQLite.');
        }
        config(['storefront_post_purchase.honduras.register_pending' => false]);
        Http::preventStrayRequests();
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->id('tie_id');
            $table->integer('tie_pais');
            $table->string('tie_codigo');
            $table->string('prism_sid')->nullable();
            $table->string('prism_store_number')->nullable();
        });
        Schema::create('prism_envios', function (Blueprint $table) {
            $table->id('pe_id');
            $table->integer('ped_id');
            $table->integer('ppa_id');
            $table->integer('pais_codigo');
            $table->string('stj_ref');
            $table->string('tienda_codigo');
            $table->string('tienda_sid');
            $table->string('integration_environment')->nullable();
            $table->string('status');
            $table->integer('intentos');
            $table->timestamps();
            $table->unique(['pais_codigo', 'stj_ref']);
        });

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

    public function test_honduras_registers_once_without_http_even_when_remote_switches_are_on(): void
    {
        $this->seedOrder('HN');
        config([
            'storefront_post_purchase.integrations_enabled' => true,
            'storefront_post_purchase.honduras.enabled' => true,
            'storefront_post_purchase.honduras.url' => 'https://prism.example/create',
            'storefront_post_purchase.honduras.register_pending' => true,
        ]);
        $this->seedStore();
        Http::fake(['*' => Http::response([], 200)]);

        app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);

        app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);
        $this->assertDatabaseCount('prism_envios', 1);
        $this->assertDatabaseHas('prism_envios', ['ped_id' => 10, 'ppa_id' => 20, 'tienda_codigo' => '002',
            'tienda_sid' => '761346871000100960', 'status' => 'pendiente', 'intentos' => 0, 'integration_environment' => 'testing']);
        Http::assertNothingSent();
    }

    public function test_registration_works_with_external_switches_off_and_preserves_existing_status(): void
    {
        $this->seedOrder('HN');
        $this->seedStore();
        config(['storefront_post_purchase.honduras.register_pending' => true,
            'storefront_post_purchase.integrations_enabled' => false, 'storefront_post_purchase.honduras.enabled' => false]);
        Http::fake();
        $service = app(StorefrontPostPurchaseIntegrationService::class);
        $service->dispatch(10, 20);
        DB::table('prism_envios')->update(['status' => 'enviado', 'intentos' => 3]);
        $service->dispatch(10, 20);
        $this->assertDatabaseCount('prism_envios', 1);
        $this->assertDatabaseHas('prism_envios', ['status' => 'enviado', 'intentos' => 3]);
        Http::assertNothingSent();
    }

    public function test_disabled_registration_does_not_call_legacy_or_create_pending(): void
    {
        $this->seedOrder('HN');
        config(['storefront_post_purchase.integrations_enabled' => true, 'storefront_post_purchase.honduras.enabled' => true]);
        Http::fake();
        app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);
        $this->assertDatabaseCount('prism_envios', 0);
        Http::assertNothingSent();
    }

    public function test_non_honduras_unapproved_and_unrelated_payments_are_not_registered(): void
    {
        $this->seedOrder('SV');
        config(['storefront_post_purchase.honduras.register_pending' => true, 'storefront_post_purchase.integrations_enabled' => false]);
        Http::fake();
        $service = app(StorefrontPostPurchaseIntegrationService::class);
        $service->dispatch(10, 20);
        DB::table('stj_paises')->update(['pai_codigo' => 'HN']);
        DB::table('stj_pedidos_pago')->update(['ppa_estado' => 'PENDIENTE']);
        $service->dispatch(10, 20);
        DB::table('stj_pedidos_pago')->update(['ppa_estado' => 'APROBADA', 'ppa_pedido' => 11]);
        $service->dispatch(10, 20);
        $this->assertDatabaseCount('prism_envios', 0);
        Http::assertNothingSent();
    }

    public function test_store_code_from_another_country_is_rejected(): void
    {
        $this->seedOrder('HN');
        DB::table('stj_tiendas')->insert(['tie_pais' => 2, 'tie_codigo' => '002', 'prism_sid' => '123', 'prism_store_number' => '2']);
        config(['storefront_post_purchase.honduras.register_pending' => true]);
        try {
            app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);
            $this->fail('Store from another country accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tienda inexistente o ambigua', $exception->getMessage());
        }
        $this->assertDatabaseCount('prism_envios', 0);
    }

    public function test_duplicate_store_or_missing_prism_mapping_is_rejected(): void
    {
        $this->seedOrder('HN');
        $this->seedStore();
        config(['storefront_post_purchase.honduras.register_pending' => true]);
        DB::table('stj_tiendas')->where('tie_pais', 1)->update(['prism_sid' => null]);
        foreach ([false, true] as $duplicate) {
            if ($duplicate) {
                $this->seedStore();
            }
            try {
                app(StorefrontPostPurchaseIntegrationService::class)->dispatch(10, 20);
                $this->fail('Invalid store accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Prism pendiente:', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('prism_envios', 0);
    }

    private function seedStore(): void
    {
        DB::table('stj_tiendas')->insert([
            ['tie_pais' => 1, 'tie_codigo' => '002', 'prism_sid' => '761346871000100960', 'prism_store_number' => '2'],
            ['tie_pais' => 2, 'tie_codigo' => '002', 'prism_sid' => '999', 'prism_store_number' => '2'],
        ]);
    }

    public function test_registration_failure_does_not_undo_approved_payment(): void
    {
        $this->seedOrder('HN');
        $this->seedStore();
        config(['storefront_post_purchase.honduras.register_pending' => true]);
        Schema::drop('prism_envios');
        $email = \Mockery::mock(StorefrontOrderConfirmationEmailService::class);
        $email->shouldReceive('send')->once()->with(10, 20);
        Log::shouldReceive('error')->once();
        Http::fake();
        $service = new StorefrontPostPurchaseService($email, app(StorefrontPostPurchaseIntegrationService::class));
        DB::transaction(fn () => $service->schedule(10, 20));
        $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_id' => 20, 'ppa_estado' => 'APROBADA']);
        Http::assertNothingSent();
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
