<?php

namespace Tests\Feature;

use App\Services\Prism\PrismShipmentProcessor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProcessPendingPrismShipmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'sqlite') {
            throw new RuntimeException('Tests require testing and SQLite.');
        }
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->integer('pai_id')->primary();
            $table->string('pai_codigo', 2);
        });
        Schema::create('prism_envios', function (Blueprint $table) {
            $table->id('pe_id');
            $table->integer('pais_codigo');
            $table->string('stj_ref');
            $table->string('integration_environment')->nullable();
            $table->string('status');
            $table->unsignedInteger('intentos')->default(0);
            $table->dateTime('last_try_at')->nullable();
            $table->dateTime('created_at');
        });
        DB::table('stj_paises')->insert([
            ['pai_id' => 7, 'pai_codigo' => 'HN'],
            ['pai_id' => 1, 'pai_codigo' => 'SV'],
        ]);
        Carbon::setTestNow('2026-09-07 15:00:00');
        config([
            'prism.hn.process_pending_enabled' => true,
            'prism.hn.max_attempts' => 5,
            'prism.hn.retry_minutes' => 15,
            'storefront_post_purchase.integrations_enabled' => true,
            'storefront_post_purchase.honduras.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_processes_exactly_one_oldest_pending_honduras_shipment(): void
    {
        $this->shipment(2, 'pendiente', 0, null, '2026-09-07 14:00:00');
        $this->shipment(1, 'pendiente', 0, null, '2026-09-07 13:00:00');
        $processor = Mockery::mock(PrismShipmentProcessor::class);
        $processor->shouldReceive('process')->once()->with(1, true)
            ->andReturn(['status' => 'enviado', 'reference' => 'STJ-1']);
        $this->app->instance(PrismShipmentProcessor::class, $processor);

        $this->artisan('prism:process-pending')->assertSuccessful();
    }

    public function test_pending_has_priority_over_retryable_error(): void
    {
        $this->shipment(1, 'error', 1, '2026-09-07 13:00:00', '2026-09-07 12:00:00');
        $this->shipment(2, 'pendiente', 0, null, '2026-09-07 14:00:00');
        $processor = Mockery::mock(PrismShipmentProcessor::class);
        $processor->shouldReceive('process')->once()->with(2, true)->andReturn(['status' => 'enviado']);
        $this->app->instance(PrismShipmentProcessor::class, $processor);

        $this->artisan('prism:process-pending')->assertSuccessful();
    }

    public function test_retries_only_after_delay_and_below_max_attempts(): void
    {
        $this->shipment(1, 'error', 1, '2026-09-07 14:50:00');
        $this->shipment(2, 'error', 5, '2026-09-07 13:00:00');
        $this->shipment(3, 'error', 4, '2026-09-07 14:45:00');
        $processor = Mockery::mock(PrismShipmentProcessor::class);
        $processor->shouldReceive('process')->once()->with(3, true)->andReturn(['status' => 'enviado']);
        $this->app->instance(PrismShipmentProcessor::class, $processor);

        $this->artisan('prism:process-pending')->assertSuccessful();
    }

    public function test_excludes_other_environments_countries_and_processing_rows(): void
    {
        $this->shipment(1, 'pendiente', 0, null, null, 'production');
        $this->shipment(2, 'procesando');
        $this->shipment(3, 'pendiente', 0, null, null, 'testing', 1);
        $processor = Mockery::mock(PrismShipmentProcessor::class);
        $processor->shouldNotReceive('process');
        $this->app->instance(PrismShipmentProcessor::class, $processor);

        $this->artisan('prism:process-pending')
            ->expectsOutput('Prism HN: no hay envíos elegibles.')
            ->assertSuccessful();
    }

    public function test_disabled_command_is_noop_and_external_switches_are_required(): void
    {
        $this->shipment(1);
        $processor = Mockery::mock(PrismShipmentProcessor::class);
        $processor->shouldNotReceive('process');
        $this->app->instance(PrismShipmentProcessor::class, $processor);

        config(['prism.hn.process_pending_enabled' => false]);
        $this->artisan('prism:process-pending')->assertSuccessful();

        config(['prism.hn.process_pending_enabled' => true, 'storefront_post_purchase.integrations_enabled' => false]);
        $this->artisan('prism:process-pending')->assertFailed();

        config(['storefront_post_purchase.integrations_enabled' => true, 'storefront_post_purchase.honduras.enabled' => false]);
        $this->artisan('prism:process-pending')->assertFailed();
    }

    public function test_processor_error_returns_failure_without_selecting_second_order(): void
    {
        $this->shipment(1, 'pendiente', 0, null, '2026-09-07 13:00:00');
        $this->shipment(2, 'pendiente', 0, null, '2026-09-07 14:00:00');
        $processor = Mockery::mock(PrismShipmentProcessor::class);
        $processor->shouldReceive('process')->once()->with(1, true)
            ->andThrow(new RuntimeException('Error controlado'));
        $this->app->instance(PrismShipmentProcessor::class, $processor);

        $this->artisan('prism:process-pending')->expectsOutput('Error controlado')->assertFailed();
    }

    private function shipment(
        int $id,
        string $status = 'pendiente',
        int $attempts = 0,
        ?string $lastTry = null,
        ?string $created = null,
        string $environment = 'testing',
        int $country = 7,
    ): void {
        DB::table('prism_envios')->insert([
            'pe_id' => $id,
            'pais_codigo' => $country,
            'stj_ref' => 'STJ-'.$id,
            'integration_environment' => $environment,
            'status' => $status,
            'intentos' => $attempts,
            'last_try_at' => $lastTry,
            'created_at' => $created ?? '2026-09-07 14:00:00',
        ]);
    }
}
