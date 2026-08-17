<?php

namespace Tests\Feature;

use App\Services\Inventory\InventorySyncClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SyncInventoryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->seedBaseData();
        config()->set('inventory.sync.endpoints', [
            'sv_categories' => 'https://inventory.test/sv',
            'gt_categories' => '',
        ]);
    }

    public function test_it_syncs_one_deterministic_batch_for_all_active_stores(): void
    {
        $client = Mockery::mock(InventorySyncClient::class);
        $client->shouldReceive('fetch')
            ->once()
            ->with(1, 'sv_categories', '001', ['P001', 'P002'])
            ->andReturn([
                'ok' => true,
                'rows' => [
                    ['code' => 'P001', 'size' => 'S', 'quantity' => 7, 'store' => '001'],
                ],
            ]);
        $client->shouldReceive('fetch')
            ->once()
            ->with(1, 'sv_categories', '002', ['P001', 'P002'])
            ->andReturn([
                'ok' => true,
                'rows' => [
                    ['code' => 'P002', 'size' => 'M', 'quantity' => 3, 'store' => '002'],
                ],
            ]);
        $this->app->instance(InventorySyncClient::class, $client);

        $this->artisan('inventory:sync', ['--country' => 'SV', '--batch-size' => 2])
            ->expectsOutputToContain('Productos: 2 | Tiendas: 2 | Filas recibidas: 2')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventario', [
            'inv_pais' => 1,
            'inv_tienda' => '001',
            'inv_codigo' => 'P001',
            'inv_talla' => 'S',
            'inv_cantidad' => 7,
        ]);
        $this->assertDatabaseHas('stj_inventario', [
            'inv_pais' => 1,
            'inv_tienda' => '001',
            'inv_codigo' => 'P002',
            'inv_talla' => 'M',
            'inv_cantidad' => 0,
        ]);
        $this->assertDatabaseHas('stj_inventory_sync_countries', [
            'isc_country_id' => 1,
            'isc_next_product_id' => 2,
            'isc_consecutive_failures' => 0,
            'isc_last_batch_products' => 2,
            'isc_last_batch_stores' => 2,
            'isc_last_batch_rows' => 2,
        ]);
    }

    public function test_it_keeps_the_cursor_and_preserves_a_store_when_its_api_call_fails(): void
    {
        $client = Mockery::mock(InventorySyncClient::class);
        $client->shouldReceive('fetch')->once()->with(1, 'sv_categories', '001', ['P001'])
            ->andReturn(['ok' => true, 'rows' => []]);
        $client->shouldReceive('fetch')->once()->with(1, 'sv_categories', '002', ['P001'])
            ->andReturn(['ok' => false, 'rows' => [], 'error' => 'timeout']);
        $this->app->instance(InventorySyncClient::class, $client);

        $this->artisan('inventory:sync', ['--country' => 'SV', '--batch-size' => 1])
            ->expectsOutputToContain('Tienda 002: timeout')
            ->assertFailed();

        $this->assertDatabaseHas('stj_inventario', [
            'inv_tienda' => '001',
            'inv_codigo' => 'P001',
            'inv_cantidad' => 0,
        ]);
        $this->assertDatabaseHas('stj_inventario', [
            'inv_tienda' => '002',
            'inv_codigo' => 'P001',
            'inv_cantidad' => 9,
        ]);
        $this->assertDatabaseHas('stj_inventory_sync_countries', [
            'isc_country_id' => 1,
            'isc_next_product_id' => 0,
            'isc_consecutive_failures' => 1,
        ]);
    }

    public function test_dry_run_does_not_call_the_endpoint_or_change_state(): void
    {
        $client = Mockery::mock(InventorySyncClient::class);
        $client->shouldNotReceive('fetch');
        $this->app->instance(InventorySyncClient::class, $client);

        $this->artisan('inventory:sync', ['--country' => 'SV', '--batch-size' => 2, '--dry-run' => true])
            ->expectsOutputToContain('Vista previa generada')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_sync_countries', [
            'isc_country_id' => 1,
            'isc_next_product_id' => 0,
            'isc_last_batch_products' => 0,
        ]);
        $this->assertDatabaseHas('stj_inventario', ['inv_tienda' => '001', 'inv_codigo' => 'P001', 'inv_cantidad' => 5]);
    }

    public function test_inactive_country_is_not_processed_even_when_sync_is_enabled(): void
    {
        DB::table('stj_paises')->where('pai_id', 1)->update(['pai_estado' => 'INACTIVO']);
        $client = Mockery::mock(InventorySyncClient::class);
        $client->shouldNotReceive('fetch');
        $this->app->instance(InventorySyncClient::class, $client);

        $this->artisan('inventory:sync', ['--country' => 'SV'])
            ->expectsOutputToContain('No hay paises activos')
            ->assertSuccessful();
    }

    public function test_it_includes_the_configured_home_delivery_store_even_when_products_flag_is_disabled(): void
    {
        config()->set('inventory.domicilio_store_by_country.sv', '57');
        DB::table('stj_tiendas')->insert([
            'tie_id' => 57,
            'tie_codigo' => '57',
            'tie_nombre' => 'Domicilio',
            'tie_pais' => 1,
            'tie_productos' => 0,
        ]);

        $client = Mockery::mock(InventorySyncClient::class);
        $client->shouldReceive('fetch')->once()->with(1, 'sv_categories', '001', ['P001'])->andReturn(['ok' => true, 'rows' => []]);
        $client->shouldReceive('fetch')->once()->with(1, 'sv_categories', '002', ['P001'])->andReturn(['ok' => true, 'rows' => []]);
        $client->shouldReceive('fetch')->once()->with(1, 'sv_categories', '57', ['P001'])->andReturn(['ok' => true, 'rows' => []]);
        $this->app->instance(InventorySyncClient::class, $client);

        $this->artisan('inventory:sync', ['--country' => 'SV', '--batch-size' => 1])
            ->expectsOutputToContain('Productos: 1 | Tiendas: 3')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_sync_countries', [
            'isc_country_id' => 1,
            'isc_last_batch_stores' => 3,
        ]);
    }

    public function test_automatic_selection_skips_an_active_country_without_a_configured_endpoint(): void
    {
        DB::table('stj_paises')->insert([
            'pai_id' => 2,
            'pai_codigo' => 'GT',
            'pai_nombre' => 'Guatemala',
            'pai_estado' => 'ACTIVO',
        ]);
        DB::table('stj_inventory_sync_countries')->insert([
            'isc_country_id' => 2,
            'isc_enabled' => 1,
            'isc_endpoint_profile' => 'gt_categories',
            'isc_batch_size' => 100,
            'isc_created_at' => now(),
            'isc_updated_at' => now(),
        ]);

        $client = Mockery::mock(InventorySyncClient::class);
        $client->shouldReceive('fetch')->once()->with(1, 'sv_categories', '001', ['P001'])->andReturn(['ok' => true, 'rows' => []]);
        $client->shouldReceive('fetch')->once()->with(1, 'sv_categories', '002', ['P001'])->andReturn(['ok' => true, 'rows' => []]);
        $this->app->instance(InventorySyncClient::class, $client);

        $this->artisan('inventory:sync', ['--batch-size' => 1])
            ->expectsOutputToContain('Pais: SV - El Salvador')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_sync_countries', [
            'isc_country_id' => 2,
            'isc_next_product_id' => 0,
            'isc_consecutive_failures' => 0,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
            $table->string('pai_codigo', 3);
            $table->string('pai_nombre');
            $table->string('pai_estado');
        });
        Schema::create('stj_inventory_sync_countries', function (Blueprint $table) {
            $table->id('isc_id');
            $table->unsignedBigInteger('isc_country_id')->unique();
            $table->boolean('isc_enabled')->default(true);
            $table->string('isc_endpoint_profile');
            $table->unsignedInteger('isc_batch_size')->default(100);
            $table->unsignedBigInteger('isc_next_product_id')->default(0);
            $table->unsignedBigInteger('isc_cycle_number')->default(1);
            $table->dateTime('isc_cycle_started_at')->nullable();
            $table->dateTime('isc_cycle_completed_at')->nullable();
            $table->dateTime('isc_last_started_at')->nullable();
            $table->dateTime('isc_last_success_at')->nullable();
            $table->dateTime('isc_last_error_at')->nullable();
            $table->text('isc_last_error')->nullable();
            $table->unsignedInteger('isc_last_batch_products')->default(0);
            $table->unsignedInteger('isc_last_batch_stores')->default(0);
            $table->unsignedInteger('isc_last_batch_rows')->default(0);
            $table->unsignedInteger('isc_consecutive_failures')->default(0);
            $table->dateTime('isc_created_at')->nullable();
            $table->dateTime('isc_updated_at')->nullable();
        });
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->id('pro_id');
            $table->string('pro_codigo');
            $table->string('pro_estatus');
        });
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ppa_producto');
            $table->unsignedBigInteger('ppa_pais');
            $table->string('ppa_estado');
        });
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->id('tie_id');
            $table->string('tie_codigo');
            $table->string('tie_nombre');
            $table->unsignedBigInteger('tie_pais');
            $table->boolean('tie_productos');
        });
        Schema::create('stj_inventario', function (Blueprint $table) {
            $table->id('inv_id');
            $table->unsignedBigInteger('inv_pais');
            $table->string('inv_tienda');
            $table->string('inv_codigo');
            $table->string('inv_talla');
            $table->integer('inv_cantidad')->nullable();
            $table->dateTime('inv_registro')->nullable();
            $table->dateTime('inv_actualizado')->nullable();
            $table->unique(['inv_tienda', 'inv_codigo', 'inv_talla', 'inv_pais']);
        });
    }

    private function seedBaseData(): void
    {
        DB::table('stj_paises')->insert([
            'pai_id' => 1,
            'pai_codigo' => 'SV',
            'pai_nombre' => 'El Salvador',
            'pai_estado' => 'ACTIVO',
        ]);
        DB::table('stj_inventory_sync_countries')->insert([
            'isc_country_id' => 1,
            'isc_enabled' => 1,
            'isc_endpoint_profile' => 'sv_categories',
            'isc_batch_size' => 100,
            'isc_created_at' => now(),
            'isc_updated_at' => now(),
        ]);
        DB::table('stj_productos')->insert([
            ['pro_id' => 1, 'pro_codigo' => 'P001', 'pro_estatus' => 'ACTIVO'],
            ['pro_id' => 2, 'pro_codigo' => 'P002', 'pro_estatus' => 'ACTIVO'],
            ['pro_id' => 3, 'pro_codigo' => 'P003', 'pro_estatus' => 'ACTIVO'],
            ['pro_id' => 4, 'pro_codigo' => 'P004', 'pro_estatus' => 'INACTIVO'],
        ]);
        DB::table('stj_producto_pais')->insert([
            ['ppa_producto' => 1, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 2, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 3, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 4, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
        ]);
        DB::table('stj_tiendas')->insert([
            ['tie_id' => 1, 'tie_codigo' => '001', 'tie_nombre' => 'Uno', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_id' => 2, 'tie_codigo' => '002', 'tie_nombre' => 'Dos', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_id' => 3, 'tie_codigo' => '999', 'tie_nombre' => 'Inactiva', 'tie_pais' => 1, 'tie_productos' => 0],
        ]);
        DB::table('stj_inventario')->insert([
            ['inv_pais' => 1, 'inv_tienda' => '001', 'inv_codigo' => 'P001', 'inv_talla' => 'S', 'inv_cantidad' => 5],
            ['inv_pais' => 1, 'inv_tienda' => '001', 'inv_codigo' => 'P002', 'inv_talla' => 'M', 'inv_cantidad' => 4],
            ['inv_pais' => 1, 'inv_tienda' => '002', 'inv_codigo' => 'P001', 'inv_talla' => 'S', 'inv_cantidad' => 9],
        ]);
    }
}
