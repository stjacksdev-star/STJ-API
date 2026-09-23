<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StartInventoryReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->seedData();
        config()->set('inventory_report.countries', [
            'SV' => ['id' => 1, 'name' => 'El Salvador', 'adapter' => 'sv'],
        ]);
    }

    public function test_it_creates_a_run_and_an_immutable_active_product_snapshot(): void
    {
        $this->artisan('inventory-report:start', ['--date' => '2026-09-22', '--country' => ['SV']])
            ->expectsOutputToContain('SV | CREADA | Corrida: 1 | Productos: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_report_runs', [
            'irr_report_date' => '2026-09-22',
            'irr_country_id' => 1,
            'irr_status' => 'CREATED',
            'irr_expected_products' => 1,
        ]);
        $this->assertDatabaseHas('stj_inventory_report_products', [
            'irp_run_id' => 1,
            'irp_product_id' => 10,
            'irp_code' => 'P001',
            'irp_year' => '2026',
            'irp_quarter' => '3',
            'irp_description' => 'Producto original',
            'irp_status' => 'PENDING',
        ]);
        $this->assertDatabaseMissing('stj_inventory_report_products', ['irp_product_id' => 11]);
        $this->assertDatabaseMissing('stj_inventory_report_products', ['irp_product_id' => 12]);
    }

    public function test_start_is_idempotent_and_does_not_rebuild_an_existing_snapshot(): void
    {
        $this->artisan('inventory-report:start', ['--date' => '2026-09-22', '--country' => ['SV']])->assertSuccessful();
        DB::table('stj_productos')->where('pro_id', 10)->update(['pro_nombre' => 'Nombre cambiado']);

        $this->artisan('inventory-report:start', ['--date' => '2026-09-22', '--country' => ['SV']])
            ->expectsOutputToContain('SV | YA EXISTIA | Corrida: 1 | Productos: 1')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('stj_inventory_report_runs')->count());
        $this->assertSame(1, DB::table('stj_inventory_report_products')->count());
        $this->assertDatabaseHas('stj_inventory_report_products', ['irp_description' => 'Producto original']);
    }

    public function test_dry_run_does_not_write_any_report_data(): void
    {
        $this->artisan('inventory-report:start', [
            '--date' => '2026-09-22', '--country' => ['SV'], '--dry-run' => true,
        ])->expectsOutputToContain('SV | VISTA PREVIA | Productos: 1')->assertSuccessful();

        $this->assertSame(0, DB::table('stj_inventory_report_runs')->count());
        $this->assertSame(0, DB::table('stj_inventory_report_products')->count());
    }

    public function test_it_rejects_an_invalid_date_before_writing(): void
    {
        $this->artisan('inventory-report:start', ['--date' => '2026-02-31', '--country' => ['SV']])
            ->expectsOutputToContain('--date debe tener formato YYYY-MM-DD')
            ->assertExitCode(2);

        $this->assertSame(0, DB::table('stj_inventory_report_runs')->count());
    }

    public function test_without_country_it_only_starts_configured_active_countries(): void
    {
        DB::table('stj_paises')->insert([
            'pai_id' => 2, 'pai_codigo' => 'GT', 'pai_nombre' => 'Guatemala', 'pai_estado' => 'INACTIVO',
        ]);
        config()->set('inventory_report.countries.GT', ['id' => 2, 'name' => 'Guatemala', 'adapter' => 'regional']);

        $this->artisan('inventory-report:start', ['--date' => '2026-09-22'])
            ->expectsOutputToContain('SV | CREADA')
            ->doesntExpectOutputToContain('GT |')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('stj_inventory_report_runs')->count());
    }

    private function createSchema(): void
    {
        Schema::create('stj_paises', function (Blueprint $table): void {
            $table->id('pai_id');
            $table->string('pai_codigo', 3);
            $table->string('pai_nombre');
            $table->string('pai_estado');
        });
        Schema::create('stj_productos', function (Blueprint $table): void {
            $table->id('pro_id');
            $table->string('pro_codigo')->nullable();
            $table->string('pro_nombre');
            $table->unsignedBigInteger('pro_categoria')->nullable();
            $table->string('pro_estatus');
            $table->string('pro_oc_anio')->nullable();
            $table->string('pro_oc_trimestre')->nullable();
            $table->string('pro_oc_coleccion')->nullable();
            $table->string('pro_oc_genero')->nullable();
            $table->string('pro_oc_marca')->nullable();
            $table->string('pro_oc_categoria')->nullable();
            $table->string('pro_oc_licencia')->nullable();
            $table->string('pro_oc_personaje')->nullable();
        });
        Schema::create('stj_producto_pais', function (Blueprint $table): void {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pais');
            $table->unsignedBigInteger('ppa_producto');
            $table->string('ppa_estado');
        });
        Schema::create('stj_inventory_report_runs', function (Blueprint $table): void {
            $table->id('irr_id');
            $table->date('irr_report_date');
            $table->unsignedBigInteger('irr_country_id');
            $table->string('irr_country_code');
            $table->string('irr_country_name');
            $table->string('irr_status')->default('CREATED');
            $table->unsignedInteger('irr_expected_products')->default(0);
            $table->dateTime('irr_started_at')->nullable();
            $table->dateTime('irr_created_at')->nullable();
            $table->dateTime('irr_updated_at')->nullable();
            $table->unique(['irr_report_date', 'irr_country_id']);
        });
        Schema::create('stj_inventory_report_products', function (Blueprint $table): void {
            $table->id('irp_id');
            $table->unsignedBigInteger('irp_run_id');
            $table->unsignedBigInteger('irp_product_id');
            $table->string('irp_code');
            $table->unsignedBigInteger('irp_category_id')->nullable();
            $table->string('irp_year')->nullable();
            $table->string('irp_quarter')->nullable();
            $table->string('irp_collection')->nullable();
            $table->string('irp_gender')->nullable();
            $table->string('irp_brand')->nullable();
            $table->string('irp_category')->nullable();
            $table->string('irp_license')->nullable();
            $table->string('irp_character')->nullable();
            $table->string('irp_description')->nullable();
            $table->string('irp_status');
            $table->unsignedTinyInteger('irp_attempts');
            $table->dateTime('irp_created_at')->nullable();
            $table->dateTime('irp_updated_at')->nullable();
            $table->unique(['irp_run_id', 'irp_product_id']);
        });
    }

    private function seedData(): void
    {
        DB::table('stj_paises')->insert([
            'pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador', 'pai_estado' => 'ACTIVO',
        ]);
        DB::table('stj_productos')->insert([
            ['pro_id' => 10, 'pro_codigo' => 'P001', 'pro_nombre' => 'Producto original', 'pro_categoria' => 4, 'pro_estatus' => 'ACTIVO', 'pro_oc_anio' => '2026', 'pro_oc_trimestre' => '3', 'pro_oc_coleccion' => 'C1', 'pro_oc_genero' => 'Nino', 'pro_oc_marca' => 'ST JACKS', 'pro_oc_categoria' => 'Camisas', 'pro_oc_licencia' => 'L1', 'pro_oc_personaje' => 'P1'],
            ['pro_id' => 11, 'pro_codigo' => 'P002', 'pro_nombre' => 'Producto inactivo', 'pro_categoria' => 4, 'pro_estatus' => 'INACTIVO', 'pro_oc_anio' => null, 'pro_oc_trimestre' => null, 'pro_oc_coleccion' => null, 'pro_oc_genero' => null, 'pro_oc_marca' => null, 'pro_oc_categoria' => null, 'pro_oc_licencia' => null, 'pro_oc_personaje' => null],
            ['pro_id' => 12, 'pro_codigo' => 'P003', 'pro_nombre' => 'Pais inactivo', 'pro_categoria' => 4, 'pro_estatus' => 'ACTIVO', 'pro_oc_anio' => null, 'pro_oc_trimestre' => null, 'pro_oc_coleccion' => null, 'pro_oc_genero' => null, 'pro_oc_marca' => null, 'pro_oc_categoria' => null, 'pro_oc_licencia' => null, 'pro_oc_personaje' => null],
        ]);
        DB::table('stj_producto_pais')->insert([
            ['ppa_pais' => 1, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO'],
            ['ppa_pais' => 1, 'ppa_producto' => 11, 'ppa_estado' => 'ACTIVO'],
            ['ppa_pais' => 1, 'ppa_producto' => 12, 'ppa_estado' => 'INACTIVO'],
        ]);
    }
}
