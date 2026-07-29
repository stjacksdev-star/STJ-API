<?php

namespace Tests\Feature;

use App\Services\PromotionLifecycleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionLifecycleServiceTest extends TestCase
{
    private PromotionLifecycleService $service;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        config()->set('promotions.timezone', 'America/El_Salvador');
        $this->createSchema();
        $this->service = app(PromotionLifecycleService::class);
        $this->now = Carbon::parse('2026-07-28 12:00:00', 'America/El_Salvador');
    }

    public function test_it_activates_a_pending_promotion(): void
    {
        $this->promotion(1, 'PENDIENTE');
        $this->schedule(1, 1, 'NORMAL', '2026-07-28 11:00:00', '2026-07-28 13:00:00', 'PENDIENTE');

        $summary = $this->service->process($this->now);

        $this->assertSame([1], $summary['candidates']['activation']);
        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 1, 'prm_estado' => 'EN-PROCESO']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 1, 'pho_estado' => 'ACTIVO']);
    }

    public function test_it_finalizes_an_ended_promotion(): void
    {
        $this->promotion(2, 'EN-PROCESO');
        $this->schedule(2, 2, 'NORMAL', '2026-07-27 10:00:00', '2026-07-28 12:00:00', 'ACTIVO');

        $summary = $this->service->process($this->now);

        $this->assertSame([2], $summary['candidates']['finalization']);
        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 2, 'prm_estado' => 'FINALIZADA']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 2, 'pho_estado' => 'FINALIZADO']);
    }

    public function test_it_starts_a_suspension(): void
    {
        $this->promotion(3, 'EN-PROCESO');
        $this->schedule(3, 3, 'NORMAL', '2026-07-28 08:00:00', '2026-07-28 18:00:00', 'ACTIVO');
        $this->schedule(4, 3, 'SUSPENDER', '2026-07-28 11:30:00', '2026-07-28 12:30:00', 'PENDIENTE');

        $summary = $this->service->process($this->now);

        $this->assertSame([3], $summary['candidates']['suspension']);
        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 3, 'prm_estado' => 'SUSPENDIDO']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 4, 'pho_estado' => 'ACTIVO']);
    }

    public function test_it_reactivates_after_suspension_while_normal_schedule_is_valid(): void
    {
        $this->promotion(4, 'SUSPENDIDO');
        $this->schedule(5, 4, 'NORMAL', '2026-07-28 08:00:00', '2026-07-28 18:00:00', 'ACTIVO');
        $this->schedule(6, 4, 'SUSPENDER', '2026-07-28 10:00:00', '2026-07-28 11:00:00', 'ACTIVO');

        $summary = $this->service->process($this->now);

        $this->assertSame([4], $summary['candidates']['reactivation']);
        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 4, 'prm_estado' => 'EN-PROCESO']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 6, 'pho_estado' => 'FINALIZADO']);
    }

    public function test_normal_end_has_precedence_over_suspension_reactivation(): void
    {
        $this->promotion(5, 'SUSPENDIDO');
        $this->schedule(7, 5, 'NORMAL', '2026-07-28 08:00:00', '2026-07-28 11:30:00', 'ACTIVO');
        $this->schedule(8, 5, 'SUSPENDER', '2026-07-28 10:00:00', '2026-07-28 11:00:00', 'ACTIVO');

        $summary = $this->service->process($this->now);

        $this->assertSame([5], $summary['candidates']['finalization']);
        $this->assertSame([], $summary['candidates']['reactivation']);
        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 5, 'prm_estado' => 'FINALIZADA']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 7, 'pho_estado' => 'FINALIZADO']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 8, 'pho_estado' => 'FINALIZADO']);
    }

    public function test_repeated_execution_is_idempotent(): void
    {
        $this->promotion(6, 'PENDIENTE');
        $this->schedule(9, 6, 'NORMAL', '2026-07-28 11:00:00', '2026-07-28 13:00:00', 'PENDIENTE');

        $first = $this->service->process($this->now);
        $second = $this->service->process($this->now);

        $this->assertCount(1, $first['transitions']);
        $this->assertCount(0, $second['transitions']);
        $this->assertDatabaseCount('stj_promociones', 1);
        $this->assertDatabaseCount('stj_promociones_horario', 1);
    }

    public function test_dry_run_reports_but_does_not_write(): void
    {
        $this->promotion(7, 'PENDIENTE');
        $this->schedule(10, 7, 'NORMAL', '2026-07-28 11:00:00', '2026-07-28 13:00:00', 'PENDIENTE');

        $summary = $this->service->process($this->now, true);

        $this->assertTrue($summary['dryRun']);
        $this->assertSame([7], $summary['candidates']['activation']);
        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 7, 'prm_estado' => 'PENDIENTE']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 10, 'pho_estado' => 'PENDIENTE']);
    }

    public function test_lifecycle_never_modifies_product_country_promotional_fields(): void
    {
        DB::table('stj_producto_pais')->insert([
            'ppa_id' => 1,
            'ppa_promo_nombre' => 'LEGACY',
            'ppa_descuento' => 35,
            'ppa_precio_tienda' => 12.50,
        ]);
        $this->promotion(8, 'PENDIENTE');
        $this->schedule(11, 8, 'NORMAL', '2026-07-28 11:00:00', '2026-07-28 13:00:00', 'PENDIENTE');

        $this->service->process($this->now);

        $this->assertDatabaseHas('stj_producto_pais', [
            'ppa_id' => 1,
            'ppa_promo_nombre' => 'LEGACY',
            'ppa_descuento' => 35,
            'ppa_precio_tienda' => 12.50,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->string('prm_estado');
            $table->string('prm_tipo_checkout')->nullable();
            $table->string('prm_alcance_tienda')->nullable();
        });
        Schema::create('stj_promociones_horario', function (Blueprint $table) {
            $table->id('pho_id');
            $table->string('pho_tipo');
            $table->unsignedBigInteger('pho_promocion');
            $table->dateTime('pho_inicio')->nullable();
            $table->dateTime('pho_fin')->nullable();
            $table->string('pho_estado');
        });
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->string('ppa_promo_nombre')->nullable();
            $table->decimal('ppa_descuento', 10, 2)->nullable();
            $table->decimal('ppa_precio_tienda', 10, 2)->nullable();
        });
    }

    private function promotion(int $id, string $status): void
    {
        DB::table('stj_promociones')->insert([
            'prm_id' => $id,
            'prm_estado' => $status,
            'prm_tipo_checkout' => 'TODO',
            'prm_alcance_tienda' => 'TODAS',
        ]);
    }

    private function schedule(
        int $id,
        int $promotionId,
        string $type,
        string $start,
        string $end,
        string $status,
    ): void {
        DB::table('stj_promociones_horario')->insert([
            'pho_id' => $id,
            'pho_tipo' => $type,
            'pho_promocion' => $promotionId,
            'pho_inicio' => $start,
            'pho_fin' => $end,
            'pho_estado' => $status,
        ]);
    }
}
