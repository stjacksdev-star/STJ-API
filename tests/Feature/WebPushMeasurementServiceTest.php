<?php

namespace Tests\Feature;

use App\Models\WebPushDelivery;
use App\Services\WebPushMeasurementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebPushMeasurementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_push_entregas', function (Blueprint $table) {
            $table->bigInteger('pen_id')->primary();
            $table->string('pen_entidad_tipo');
            $table->bigInteger('pen_entidad_id');
            $table->string('pen_stage');
            $table->string('pen_action');
            $table->text('pen_payload')->nullable();
            $table->string('pen_estado');
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

        DB::table('stj_push_entregas')->insert([
            'pen_id' => 1,
            'pen_entidad_tipo' => 'CART',
            'pen_entidad_id' => 10,
            'pen_stage' => 'PRIMARY',
            'pen_action' => 'https://shop.example.test/sv/carrito',
            'pen_payload' => json_encode(['automation' => 'ABANDONED_CART']),
            'pen_estado' => 'ENVIADO',
        ]);
    }

    public function test_click_and_conversion_are_idempotent_per_delivery(): void
    {
        $service = app(WebPushMeasurementService::class);
        $delivery = WebPushDelivery::query()->findOrFail(1);
        $now = CarbonImmutable::parse('2026-08-12 12:00:00');

        $this->assertTrue($service->recordClick($delivery, $now));
        $this->assertFalse($service->recordClick($delivery, $now->addMinute()));
        $this->assertSame(1, $service->recordCartConverted(10, 500, $now->addHour()));
        $this->assertSame(0, $service->recordCartConverted(10, 500, $now->addHours(2)));

        $this->assertDatabaseCount('stj_push_eventos', 2);
        $this->assertDatabaseHas('stj_push_eventos', ['pev_entrega_id' => 1, 'pev_tipo' => 'CLICK']);
        $this->assertDatabaseHas('stj_push_eventos', ['pev_entrega_id' => 1, 'pev_tipo' => 'CONVERTED']);
    }

    public function test_signed_click_route_records_once_and_redirects_to_stored_action(): void
    {
        $url = URL::temporarySignedRoute(
            'storefront.push.click',
            now()->addHour(),
            ['delivery' => 1],
            absolute: false,
        );

        $this->get($url)->assertRedirect('https://shop.example.test/sv/carrito');
        $this->get($url)->assertRedirect('https://shop.example.test/sv/carrito');

        $this->assertSame(1, DB::table('stj_push_eventos')->where('pev_tipo', 'CLICK')->count());
    }

    public function test_click_route_rejects_an_invalid_signature(): void
    {
        $this->get('/api/storefront/push/deliveries/1/click?expires=9999999999&signature=invalid')
            ->assertForbidden();

        $this->assertDatabaseCount('stj_push_eventos', 0);
    }
}
