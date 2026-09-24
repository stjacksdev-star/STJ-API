<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinalizeInventoryReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        $this->createSchema();
    }

    public function test_it_closes_remaining_products_with_auditable_terminal_states(): void
    {
        $this->seedRun();

        $this->artisan('inventory-report:finalize', ['--date' => '2026-09-23'])
            ->expectsOutputToContain('SV | PARTIAL | Encontrados: 1 | No devueltos: 1 | Fallidos: 2 | Filas: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_inventory_report_products', ['irp_id' => 2, 'irp_status' => 'NOT_RETURNED']);
        $this->assertDatabaseHas('stj_inventory_report_products', ['irp_id' => 3, 'irp_status' => 'FAILED']);
        $this->assertDatabaseHas('stj_inventory_report_products', ['irp_id' => 4, 'irp_status' => 'FAILED']);
        $this->assertDatabaseHas('stj_inventory_report_requests', ['irq_id' => 1, 'irq_status' => 'FAILED']);
        $this->assertDatabaseHas('stj_inventory_report_runs', [
            'irr_id' => 1,
            'irr_status' => 'PARTIAL',
            'irr_found_products' => 1,
            'irr_not_returned_products' => 1,
            'irr_failed_products' => 2,
            'irr_result_rows' => 1,
        ]);
    }

    public function test_it_is_idempotent_after_the_run_is_closed(): void
    {
        $this->seedRun();
        $this->artisan('inventory-report:finalize', ['--date' => '2026-09-23'])->assertSuccessful();
        $this->artisan('inventory-report:finalize', ['--date' => '2026-09-23'])
            ->expectsOutputToContain('No hay corridas abiertas para 2026-09-23.')
            ->assertSuccessful();
    }

    private function createSchema(): void
    {
        Schema::create('stj_inventory_report_runs', function (Blueprint $table): void {
            $table->id('irr_id');
            $table->date('irr_report_date');
            $table->string('irr_country_code');
            $table->string('irr_status');
            $table->unsignedInteger('irr_found_products')->default(0);
            $table->unsignedInteger('irr_not_returned_products')->default(0);
            $table->unsignedInteger('irr_not_found_products')->default(0);
            $table->unsignedInteger('irr_failed_products')->default(0);
            $table->unsignedBigInteger('irr_result_rows')->default(0);
            $table->dateTime('irr_queries_closed_at')->nullable();
            $table->dateTime('irr_completed_at')->nullable();
            $table->dateTime('irr_updated_at')->nullable();
        });
        Schema::create('stj_inventory_report_products', function (Blueprint $table): void {
            $table->id('irp_id');
            $table->unsignedBigInteger('irp_run_id');
            $table->string('irp_status');
            $table->text('irp_last_error')->nullable();
            $table->dateTime('irp_completed_at')->nullable();
            $table->dateTime('irp_updated_at')->nullable();
        });
        Schema::create('stj_inventory_report_requests', function (Blueprint $table): void {
            $table->id('irq_id');
            $table->unsignedBigInteger('irq_run_id');
            $table->string('irq_status');
            $table->text('irq_error')->nullable();
            $table->dateTime('irq_completed_at')->nullable();
        });
        Schema::create('stj_inventory_report_rows', function (Blueprint $table): void {
            $table->id('irw_id');
            $table->unsignedBigInteger('irw_run_id');
        });
    }

    private function seedRun(): void
    {
        DB::table('stj_inventory_report_runs')->insert([
            'irr_id' => 1, 'irr_report_date' => '2026-09-23', 'irr_country_code' => 'SV',
            'irr_status' => 'PROCESSING', 'irr_updated_at' => now(),
        ]);
        DB::table('stj_inventory_report_products')->insert([
            ['irp_id' => 1, 'irp_run_id' => 1, 'irp_status' => 'FOUND', 'irp_last_error' => null],
            ['irp_id' => 2, 'irp_run_id' => 1, 'irp_status' => 'RETRY_PENDING', 'irp_last_error' => 'El endpoint no devolvio el producto solicitado.'],
            ['irp_id' => 3, 'irp_run_id' => 1, 'irp_status' => 'RETRY_PENDING', 'irp_last_error' => 'HTTP 503'],
            ['irp_id' => 4, 'irp_run_id' => 1, 'irp_status' => 'PENDING', 'irp_last_error' => null],
        ]);
        DB::table('stj_inventory_report_requests')->insert([
            'irq_id' => 1, 'irq_run_id' => 1, 'irq_status' => 'PENDING',
        ]);
        DB::table('stj_inventory_report_rows')->insert(['irw_id' => 1, 'irw_run_id' => 1]);
    }
}
