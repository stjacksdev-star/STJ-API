<?php

namespace Tests\Feature;

use App\Services\AbandonedCartPushEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AbandonedCartPushEvaluatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->bigInteger('pai_id')->primary();
            $table->string('pai_codigo');
        });
        Schema::create('stj_carritos', function (Blueprint $table) {
            $table->bigInteger('car_id')->primary();
            $table->uuid('car_uuid');
            $table->bigInteger('car_visitante_id');
            $table->bigInteger('car_usu_id')->nullable();
            $table->bigInteger('car_pais_id');
            $table->bigInteger('car_pedido_id')->nullable();
            $table->string('car_estado');
            $table->unsignedInteger('car_version');
            $table->dateTime('car_ultima_actividad_en');
            $table->dateTime('car_expira_en');
        });
        Schema::create('stj_carrito_detalles', function (Blueprint $table) {
            $table->bigInteger('cad_id')->primary();
            $table->bigInteger('cad_carrito_id');
        });
        Schema::create('stj_push_suscripciones', function (Blueprint $table) {
            $table->bigInteger('psu_id')->primary();
            $table->bigInteger('psu_visitante_id')->nullable();
            $table->bigInteger('psu_usu_id')->nullable();
            $table->bigInteger('psu_pais_id');
            $table->string('psu_plataforma');
            $table->string('psu_estado');
            $table->string('psu_permiso');
        });
        Schema::create('stj_push_automatizaciones', function (Blueprint $table) {
            $table->bigInteger('pau_id')->primary();
            $table->string('pau_codigo')->unique();
            $table->string('pau_estado');
            $table->text('pau_paises')->nullable();
            $table->unsignedInteger('pau_retraso_minutos');
            $table->unsignedInteger('pau_cooldown_horas');
            $table->unsignedSmallInteger('pau_maximo_por_entidad');
            $table->string('pau_titulo_plantilla');
            $table->string('pau_cuerpo_plantilla');
            $table->string('pau_action_plantilla');
            $table->string('pau_imagen')->nullable();
        });
        Schema::create('stj_push_entregas', function (Blueprint $table) {
            $table->bigInteger('pen_id', true);
            $table->bigInteger('pen_automatizacion_id');
            $table->bigInteger('pen_suscripcion_id')->nullable();
            $table->bigInteger('pen_visitante_id')->nullable();
            $table->bigInteger('pen_usu_id')->nullable();
            $table->bigInteger('pen_pais_id');
            $table->string('pen_entidad_tipo');
            $table->bigInteger('pen_entidad_id');
            $table->unsignedInteger('pen_entidad_version')->nullable();
            $table->string('pen_stage');
            $table->string('pen_idempotency_key')->unique();
            $table->string('pen_titulo');
            $table->string('pen_cuerpo');
            $table->string('pen_action');
            $table->string('pen_imagen')->nullable();
            $table->text('pen_payload')->nullable();
            $table->string('pen_estado');
            $table->unsignedSmallInteger('pen_intentos');
            $table->dateTime('pen_programado_en');
            $table->dateTime('pen_disponible_en');
            $table->dateTime('pen_creado_en');
            $table->dateTime('pen_actualizado_en');
        });
    }

    public function test_it_creates_one_delivery_and_does_not_duplicate_a_recalculation(): void
    {
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');
        $this->seedCandidate($now);
        $evaluator = app(AbandonedCartPushEvaluator::class);

        $first = $evaluator->evaluate($now);
        $second = $evaluator->evaluate($now->addMinute());

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $second['existing']);
        $this->assertDatabaseCount('stj_push_entregas', 1);
        $this->assertDatabaseHas('stj_push_entregas', [
            'pen_idempotency_key' => 'ABANDONED_CART:10:3:PRIMARY:20',
            'pen_estado' => 'PENDIENTE',
            'pen_entidad_version' => 3,
            'pen_suscripcion_id' => 20,
        ]);
    }

    public function test_dry_run_detects_without_creating_and_cooldown_blocks_a_new_version(): void
    {
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');
        $this->seedCandidate($now);
        $evaluator = app(AbandonedCartPushEvaluator::class);

        $dryRun = $evaluator->evaluate($now, dryRun: true);
        $evaluator->evaluate($now);
        DB::table('stj_carritos')->where('car_id', 10)->update(['car_version' => 4]);
        $nextVersion = $evaluator->evaluate($now->addHour());

        $this->assertSame(1, $dryRun['would_create']);
        $this->assertSame(1, $nextVersion['cooldown']);
        $this->assertDatabaseCount('stj_push_entregas', 1);
    }

    private function seedCandidate(CarbonImmutable $now): void
    {
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_push_automatizaciones')->insert([
            'pau_id' => 1,
            'pau_codigo' => 'ABANDONED_CART',
            'pau_estado' => 'ACTIVA',
            'pau_paises' => json_encode(['SV']),
            'pau_retraso_minutos' => 120,
            'pau_cooldown_horas' => 24,
            'pau_maximo_por_entidad' => 1,
            'pau_titulo_plantilla' => 'Tu carrito {cart_id}',
            'pau_cuerpo_plantilla' => 'Continua tu compra',
            'pau_action_plantilla' => 'https://example.test/{country}/carrito',
        ]);
        DB::table('stj_carritos')->insert([
            'car_id' => 10,
            'car_uuid' => '00000000-0000-0000-0000-000000000010',
            'car_visitante_id' => 30,
            'car_usu_id' => null,
            'car_pais_id' => 1,
            'car_pedido_id' => null,
            'car_estado' => 'ACTIVO',
            'car_version' => 3,
            'car_ultima_actividad_en' => $now->subHours(3),
            'car_expira_en' => $now->addDay(),
        ]);
        DB::table('stj_carrito_detalles')->insert(['cad_id' => 40, 'cad_carrito_id' => 10]);
        DB::table('stj_push_suscripciones')->insert([
            'psu_id' => 20,
            'psu_visitante_id' => 30,
            'psu_usu_id' => null,
            'psu_pais_id' => 1,
            'psu_plataforma' => 'WEB',
            'psu_estado' => 'ACTIVA',
            'psu_permiso' => 'GRANTED',
        ]);
    }
}
