<?php

namespace Tests\Feature;

use App\Services\FirebasePushService;
use App\Services\WebPushDeliveryProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WebPushDeliveryProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_carritos', function (Blueprint $table) {
            $table->bigInteger('car_id')->primary();
            $table->bigInteger('car_visitante_id');
            $table->bigInteger('car_usu_id')->nullable();
            $table->bigInteger('car_pais_id');
            $table->bigInteger('car_pedido_id')->nullable();
            $table->string('car_estado');
            $table->unsignedInteger('car_version');
            $table->dateTime('car_expira_en');
            $table->dateTime('car_convertido_en')->nullable();
        });
        Schema::create('stj_carrito_detalles', function (Blueprint $table) {
            $table->bigInteger('cad_id')->primary();
            $table->bigInteger('cad_carrito_id');
        });
        Schema::create('stj_push_suscripciones', function (Blueprint $table) {
            $table->bigInteger('psu_id')->primary();
            $table->bigInteger('psu_pais_id');
            $table->string('psu_token');
            $table->string('psu_plataforma');
            $table->string('psu_estado');
            $table->string('psu_permiso');
            $table->dateTime('psu_actualizado_en')->nullable();
        });
        Schema::create('stj_push_entregas', function (Blueprint $table) {
            $table->bigInteger('pen_id')->primary();
            $table->bigInteger('pen_suscripcion_id')->nullable();
            $table->bigInteger('pen_visitante_id')->nullable();
            $table->bigInteger('pen_usu_id')->nullable();
            $table->bigInteger('pen_pais_id');
            $table->string('pen_entidad_tipo');
            $table->bigInteger('pen_entidad_id');
            $table->unsignedInteger('pen_entidad_version')->nullable();
            $table->string('pen_stage');
            $table->string('pen_titulo');
            $table->string('pen_cuerpo');
            $table->string('pen_action');
            $table->string('pen_imagen')->nullable();
            $table->text('pen_payload')->nullable();
            $table->string('pen_estado');
            $table->unsignedSmallInteger('pen_intentos');
            $table->dateTime('pen_programado_en');
            $table->dateTime('pen_disponible_en');
            $table->dateTime('pen_bloqueado_en')->nullable();
            $table->string('pen_bloqueado_por')->nullable();
            $table->dateTime('pen_ultimo_intento_en')->nullable();
            $table->dateTime('pen_enviado_en')->nullable();
            $table->text('pen_resultado')->nullable();
            $table->text('pen_error')->nullable();
            $table->dateTime('pen_creado_en');
            $table->dateTime('pen_actualizado_en');
        });
        Schema::create('stj_push_eventos', function (Blueprint $table) {
            $table->bigInteger('pev_id', true);
            $table->bigInteger('pev_entrega_id');
            $table->uuid('pev_event_uuid')->nullable()->unique();
            $table->string('pev_tipo');
            $table->string('pev_origen');
            $table->text('pev_datos')->nullable();
            $table->dateTime('pev_ocurrido_en');
            $table->dateTime('pev_recibido_en');
        });
    }

    public function test_it_sends_a_valid_delivery_and_records_sent(): void
    {
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');
        $this->seedDelivery($now);
        config()->set('push_web.public_base_url', 'https://api.example.test');
        $firebase = Mockery::mock(FirebasePushService::class);
        $firebase->shouldReceive('sendToTokens')->once()->with(
            ['web-token'], '¿Olvidaste algo?', 'Tu carrito te espera',
            Mockery::on(fn ($data) => $data['delivery_id'] === '1'
                && str_starts_with($data['click_action'], 'https://api.example.test/api/storefront/push/deliveries/1/click?')
                && str_contains($data['click_action'], 'signature=')),
        )->andReturn(['sent' => 1, 'failed' => 0, 'results' => [['ok' => true, 'result' => '{"name":"message/1"}']]]);

        $summary = (new WebPushDeliveryProcessor($firebase))->process(1, processedAt: $now);

        $this->assertSame(1, $summary['sent']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 1, 'pen_estado' => 'ENVIADO', 'pen_intentos' => 1]);
        $this->assertDatabaseHas('stj_push_eventos', ['pev_entrega_id' => 1, 'pev_tipo' => 'SENT']);
    }

    public function test_it_cancels_a_delivery_when_the_cart_version_changed_without_calling_firebase(): void
    {
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');
        $this->seedDelivery($now);
        DB::table('stj_carritos')->where('car_id', 10)->update(['car_version' => 4]);
        $firebase = Mockery::mock(FirebasePushService::class);
        $firebase->shouldNotReceive('sendToTokens');

        $summary = (new WebPushDeliveryProcessor($firebase))->process(1, processedAt: $now);

        $this->assertSame(1, $summary['cancelled']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 1, 'pen_estado' => 'CANCELADO', 'pen_intentos' => 0]);
        $this->assertDatabaseCount('stj_push_eventos', 0);
    }

    public function test_it_marks_an_invalid_token_and_records_the_event(): void
    {
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');
        $this->seedDelivery($now);
        $firebase = Mockery::mock(FirebasePushService::class);
        $firebase->shouldReceive('sendToTokens')->once()->andReturn([
            'sent' => 0,
            'failed' => 1,
            'results' => [['ok' => false, 'result' => 'UNREGISTERED']],
        ]);
        $firebase->shouldReceive('isInvalidTokenResult')->once()->with('UNREGISTERED')->andReturnTrue();

        $summary = (new WebPushDeliveryProcessor($firebase))->process(1, processedAt: $now);

        $this->assertSame(1, $summary['invalid']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 1, 'pen_estado' => 'DESCARTADO', 'pen_intentos' => 1]);
        $this->assertDatabaseHas('stj_push_suscripciones', ['psu_id' => 20, 'psu_estado' => 'INVALIDA']);
        $this->assertDatabaseHas('stj_push_eventos', ['pev_entrega_id' => 1, 'pev_tipo' => 'INVALID_TOKEN']);
    }

    public function test_it_retries_transient_failures_and_stops_after_three_attempts(): void
    {
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');
        $this->seedDelivery($now);
        $firebase = Mockery::mock(FirebasePushService::class);
        $firebase->shouldReceive('sendToTokens')->times(3)->andReturn([
            'sent' => 0,
            'failed' => 1,
            'results' => [['ok' => false, 'result' => 'SERVICE_UNAVAILABLE']],
        ]);
        $firebase->shouldReceive('isInvalidTokenResult')->times(3)->andReturnFalse();
        $processor = new WebPushDeliveryProcessor($firebase);

        $first = $processor->process(1, processedAt: $now);
        $second = $processor->process(1, processedAt: $now->addMinutes(5));
        $third = $processor->process(1, processedAt: $now->addMinutes(15));

        $this->assertSame(1, $first['retry']);
        $this->assertSame(1, $second['retry']);
        $this->assertSame(1, $third['failed']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 1, 'pen_estado' => 'ERROR', 'pen_intentos' => 3]);
    }

    public function test_it_marks_stale_processing_as_error_without_resending(): void
    {
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');
        $this->seedDelivery($now);
        DB::table('stj_push_entregas')->where('pen_id', 1)->update([
            'pen_estado' => 'PROCESANDO',
            'pen_bloqueado_en' => $now->subMinutes(16),
            'pen_bloqueado_por' => 'dead-worker',
        ]);
        $firebase = Mockery::mock(FirebasePushService::class);
        $firebase->shouldNotReceive('sendToTokens');

        $summary = (new WebPushDeliveryProcessor($firebase))->process(processedAt: $now);

        $this->assertSame(1, $summary['stale_failed']);
        $this->assertSame(0, $summary['pending']);
        $this->assertDatabaseHas('stj_push_entregas', [
            'pen_id' => 1,
            'pen_estado' => 'ERROR',
            'pen_bloqueado_en' => null,
            'pen_bloqueado_por' => null,
        ]);
    }

    private function seedDelivery(CarbonImmutable $now): void
    {
        DB::table('stj_carritos')->insert([
            'car_id' => 10,
            'car_visitante_id' => 30,
            'car_usu_id' => null,
            'car_pais_id' => 1,
            'car_pedido_id' => null,
            'car_estado' => 'ACTIVO',
            'car_version' => 3,
            'car_expira_en' => $now->addDay(),
            'car_convertido_en' => null,
        ]);
        DB::table('stj_carrito_detalles')->insert(['cad_id' => 40, 'cad_carrito_id' => 10]);
        DB::table('stj_push_suscripciones')->insert([
            'psu_id' => 20,
            'psu_pais_id' => 1,
            'psu_token' => 'web-token',
            'psu_plataforma' => 'WEB',
            'psu_estado' => 'ACTIVA',
            'psu_permiso' => 'GRANTED',
        ]);
        DB::table('stj_push_entregas')->insert([
            'pen_id' => 1,
            'pen_suscripcion_id' => 20,
            'pen_visitante_id' => 30,
            'pen_usu_id' => null,
            'pen_pais_id' => 1,
            'pen_entidad_tipo' => 'CART',
            'pen_entidad_id' => 10,
            'pen_entidad_version' => 3,
            'pen_stage' => 'PRIMARY',
            'pen_titulo' => '¿Olvidaste algo?',
            'pen_cuerpo' => 'Tu carrito te espera',
            'pen_action' => 'https://example.test/sv/carrito',
            'pen_payload' => json_encode(['automation' => 'ABANDONED_CART']),
            'pen_estado' => 'PENDIENTE',
            'pen_intentos' => 0,
            'pen_programado_en' => $now->subMinute(),
            'pen_disponible_en' => $now->subMinute(),
            'pen_creado_en' => $now->subMinute(),
            'pen_actualizado_en' => $now->subMinute(),
        ]);
    }
}
