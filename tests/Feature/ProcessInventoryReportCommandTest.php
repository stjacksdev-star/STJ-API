<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessInventoryReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->seedRun();
        config()->set('cache.default', 'array');
        config()->set('inventory_report.max_attempts', 2);
        config()->set('inventory_report.batch_size', 100);
        config()->set('inventory_report.countries.SV', [
            'id' => 1,
            'name' => 'El Salvador',
            'adapter' => 'sv',
            'url' => 'https://inventory.test/sv',
            'token' => 'secret',
            'stores' => ['019'],
        ]);
    }

    public function test_it_persists_rows_and_retries_only_products_omitted_by_the_api(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence('https://inventory.test/sv')
            ->push(['datos' => [[
                'estilo' => 'P001', 'tienda' => 'Tienda 1', 'talla' => 'M', 'existencia' => '7', 'PRECIO' => '19.95',
            ]]])
            ->push(['datos' => []]);

        $this->artisan('inventory-report:process', ['--date' => '2026-09-23', '--country' => 'SV'])
            ->expectsOutputToContain('Encontrados: 1')
            ->expectsOutputToContain('Reintento: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_report_products', ['irp_code' => 'P001', 'irp_status' => 'FOUND', 'irp_attempts' => 1]);
        $this->assertDatabaseHas('stj_inventory_report_products', ['irp_code' => 'P002', 'irp_status' => 'RETRY_PENDING', 'irp_attempts' => 1]);
        $this->assertDatabaseHas('stj_inventory_report_rows', [
            'irw_product_id' => 1, 'irw_store' => 'Tienda 1', 'irw_size' => 'M', 'irw_quantity' => 7, 'irw_sale_price' => 19.95,
        ]);

        $this->artisan('inventory-report:process', ['--date' => '2026-09-23', '--country' => 'SV'])
            ->expectsOutputToContain('No devueltos: 1')
            ->expectsOutputToContain('Estado de corrida: PARTIAL')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_report_products', ['irp_code' => 'P002', 'irp_status' => 'NOT_RETURNED', 'irp_attempts' => 2]);
        $this->assertDatabaseHas('stj_inventory_report_runs', [
            'irr_status' => 'PARTIAL', 'irr_found_products' => 1, 'irr_not_returned_products' => 1, 'irr_failed_products' => 0, 'irr_result_rows' => 1,
        ]);
        $this->assertSame(2, DB::table('stj_inventory_report_requests')->count());
    }

    public function test_http_failures_become_terminal_only_after_the_last_attempt(): void
    {
        Http::fake(['https://inventory.test/sv' => Http::response([], 503)]);

        $this->artisan('inventory-report:process', ['--date' => '2026-09-23', '--country' => 'SV'])
            ->expectsOutputToContain('Reintento: 2')
            ->assertFailed();
        $this->assertSame(2, DB::table('stj_inventory_report_products')->where('irp_status', 'RETRY_PENDING')->count());

        $this->artisan('inventory-report:process', ['--date' => '2026-09-23', '--country' => 'SV'])
            ->expectsOutputToContain('Fallidos: 2')
            ->expectsOutputToContain('Estado de corrida: PARTIAL')
            ->assertFailed();

        $this->assertSame(2, DB::table('stj_inventory_report_products')->where('irp_status', 'FAILED')->count());
        $this->assertDatabaseHas('stj_inventory_report_requests', ['irq_http_status' => 503, 'irq_status' => 'FAILED']);
    }

    public function test_a_fully_returned_batch_completes_the_run(): void
    {
        Http::fake(['https://inventory.test/sv' => Http::response(['datos' => [
            ['estilo' => 'P001', 'tienda' => 'T1', 'talla' => 'M', 'existencia' => 1, 'PRECIO' => 10],
            ['estilo' => 'P002', 'tienda' => 'T1', 'talla' => 'L', 'existencia' => 0, 'PRECIO' => 20],
        ]])]);

        $this->artisan('inventory-report:process', ['--date' => '2026-09-23', '--country' => 'SV'])
            ->expectsOutputToContain('Estado de corrida: COMPLETE')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_report_runs', [
            'irr_status' => 'COMPLETE', 'irr_found_products' => 2, 'irr_result_rows' => 2,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('stj_inventory_report_runs', function (Blueprint $table): void {
            $table->id('irr_id');
            $table->date('irr_report_date');
            $table->unsignedBigInteger('irr_country_id');
            $table->string('irr_country_code');
            $table->string('irr_country_name');
            $table->string('irr_status');
            $table->unsignedInteger('irr_expected_products')->default(0);
            $table->unsignedInteger('irr_found_products')->default(0);
            $table->unsignedInteger('irr_not_returned_products')->default(0);
            $table->unsignedInteger('irr_not_found_products')->default(0);
            $table->unsignedInteger('irr_failed_products')->default(0);
            $table->unsignedBigInteger('irr_result_rows')->default(0);
            $table->dateTime('irr_started_at')->nullable();
            $table->dateTime('irr_queries_closed_at')->nullable();
            $table->dateTime('irr_completed_at')->nullable();
            $table->text('irr_last_error')->nullable();
            $table->dateTime('irr_created_at')->nullable();
            $table->dateTime('irr_updated_at')->nullable();
        });
        Schema::create('stj_inventory_report_products', function (Blueprint $table): void {
            $table->id('irp_id');
            $table->unsignedBigInteger('irp_run_id');
            $table->unsignedBigInteger('irp_product_id');
            $table->string('irp_code');
            $table->string('irp_status');
            $table->unsignedTinyInteger('irp_attempts')->default(0);
            $table->unsignedSmallInteger('irp_last_http_status')->nullable();
            $table->text('irp_last_error')->nullable();
            $table->dateTime('irp_first_requested_at')->nullable();
            $table->dateTime('irp_last_requested_at')->nullable();
            $table->dateTime('irp_completed_at')->nullable();
            $table->dateTime('irp_created_at')->nullable();
            $table->dateTime('irp_updated_at')->nullable();
        });
        Schema::create('stj_inventory_report_requests', function (Blueprint $table): void {
            $table->id('irq_id');
            $table->unsignedBigInteger('irq_run_id');
            $table->string('irq_adapter');
            $table->unsignedTinyInteger('irq_attempt');
            $table->uuid('irq_batch_key');
            $table->string('irq_endpoint');
            $table->unsignedInteger('irq_requested_products');
            $table->unsignedInteger('irq_returned_products')->default(0);
            $table->unsignedBigInteger('irq_returned_rows')->default(0);
            $table->unsignedSmallInteger('irq_http_status')->nullable();
            $table->unsignedInteger('irq_duration_ms')->nullable();
            $table->string('irq_status');
            $table->text('irq_error')->nullable();
            $table->dateTime('irq_started_at')->nullable();
            $table->dateTime('irq_completed_at')->nullable();
            $table->dateTime('irq_created_at')->nullable();
        });
        Schema::create('stj_inventory_report_rows', function (Blueprint $table): void {
            $table->id('irw_id');
            $table->unsignedBigInteger('irw_run_id');
            $table->unsignedBigInteger('irw_product_id');
            $table->unsignedBigInteger('irw_request_id')->nullable();
            $table->string('irw_store');
            $table->string('irw_size');
            $table->decimal('irw_quantity', 18, 4);
            $table->decimal('irw_sale_price', 18, 4)->nullable();
            $table->dateTime('irw_created_at')->nullable();
            $table->dateTime('irw_updated_at')->nullable();
            $table->unique(['irw_run_id', 'irw_product_id', 'irw_store', 'irw_size']);
        });
    }

    private function seedRun(): void
    {
        DB::table('stj_inventory_report_runs')->insert([
            'irr_id' => 1,
            'irr_report_date' => '2026-09-23',
            'irr_country_id' => 1,
            'irr_country_code' => 'SV',
            'irr_country_name' => 'El Salvador',
            'irr_status' => 'CREATED',
            'irr_expected_products' => 2,
            'irr_created_at' => now(),
            'irr_updated_at' => now(),
        ]);
        DB::table('stj_inventory_report_products')->insert([
            ['irp_id' => 1, 'irp_run_id' => 1, 'irp_product_id' => 10, 'irp_code' => 'P001', 'irp_status' => 'PENDING', 'irp_attempts' => 0],
            ['irp_id' => 2, 'irp_run_id' => 1, 'irp_product_id' => 11, 'irp_code' => 'P002', 'irp_status' => 'PENDING', 'irp_attempts' => 0],
        ]);
    }
}
