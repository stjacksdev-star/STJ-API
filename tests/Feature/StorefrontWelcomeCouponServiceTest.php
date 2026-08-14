<?php

namespace Tests\Feature;

use App\Services\StorefrontWelcomeCouponService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontWelcomeCouponServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->schema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_creates_a_personal_coupon_from_the_registration_template_for_fifteen_days(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        $this->seedTemplate();

        $coupon = app(StorefrontWelcomeCouponService::class)->issue(1, 'SV', ' NEW@Example.com ', 'Ana López');

        $this->assertNotNull($coupon);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{6}$/', $coupon['code']);
        $this->assertDatabaseHas('stj_cupones_header', [
            'che_id' => $coupon['headerId'], 'che_generico' => 'NO', 'che_pais' => 1,
            'che_inicio' => '2026-08-13 10:00:00', 'che_final' => '2026-08-28 10:00:00',
            'che_descuento' => 20, 'che_aplica_promo' => 'REGULAR', 'che_config_automatica' => null,
        ]);
        $this->assertDatabaseHas('stj_cupones', [
            'cup_id' => $coupon['id'], 'cup_header' => $coupon['headerId'], 'cup_estado' => 'ACTIVO',
            'cup_descuento' => 20, 'cup_correo' => 'new@example.com', 'cup_vigencia' => 15,
        ]);
        $this->assertDatabaseHas('stj_cupones_producto', ['cpr_cupon' => $coupon['headerId'], 'cpr_producto' => 501]);
    }

    public function test_it_sends_the_email_and_marks_the_coupon(): void
    {
        config()->set('services.smtp2go.url', 'https://api.smtp2go.test/v3/email/send');
        config()->set('services.smtp2go.key', 'test-key');
        config()->set('services.smtp2go.sender', 'no-reply@example.com');
        Http::fake(['*' => Http::response(['data' => ['succeeded' => 1, 'failed' => 0]])]);
        $this->seedTemplate();
        $service = app(StorefrontWelcomeCouponService::class);
        $coupon = $service->issue(1, 'SV', 'new@example.com', 'Ana López');

        $service->sendWelcomeEmail($coupon);

        $this->assertDatabaseHas('stj_cupones', ['cup_id' => $coupon['id'], 'cup_correo_enviado' => 1]);
        Http::assertSent(fn ($request) => $request['to'][0] === '"Ana López" <new@example.com>'
            && str_contains($request['html_body'], $coupon['code'])
            && str_contains($request['html_body'], '20 % de descuento'));
    }

    private function seedTemplate(): void
    {
        DB::table('stj_cupones_header')->insert([
            'che_id' => 62, 'che_aplica' => 'TODO', 'che_tipo' => 'DESCUENTO', 'che_checkout' => 'TODO',
            'che_generico' => 'NO', 'che_nombre' => 'Cupón de registro', 'che_regional' => 'NO', 'che_pais' => 1,
            'che_inicio' => '2025-01-01', 'che_final' => '2025-01-02', 'che_monto' => 0, 'che_descuento' => 20,
            'che_aplica_monto_minimo' => 'NO', 'che_monto_minimo' => 0, 'che_multiple' => 'NO',
            'che_aplica_promo' => 'REGULAR', 'che_solo_primera_compra' => 'NO', 'che_estado' => 'ACTIVO',
            'che_config_automatica' => 'REGISTRO_EMAIL', 'che_tipo_productos' => 'PLA', 'che_para' => 'NA',
        ]);
        DB::table('stj_cupones_producto')->insert(['cpr_cupon' => 62, 'cpr_producto' => 501, 'cpr_descuento' => 20]);
    }

    private function schema(): void
    {
        Schema::create('stj_cupones_header', function (Blueprint $t) {
            $t->id('che_id');
            foreach (['che_aplica', 'che_tipo', 'che_checkout', 'che_generico', 'che_nombre', 'che_regional', 'che_aplica_monto_minimo', 'che_multiple', 'che_aplica_promo', 'che_solo_primera_compra', 'che_nombre_comercial', 'che_estado', 'che_config_automatica', 'che_tipo_productos', 'che_para'] as $column) $t->string($column)->nullable();
            $t->unsignedBigInteger('che_pais')->nullable();
            $t->dateTime('che_inicio')->nullable();
            $t->dateTime('che_final')->nullable();
            $t->decimal('che_monto')->nullable();
            $t->decimal('che_descuento')->nullable();
            $t->decimal('che_monto_minimo')->nullable();
        });
        Schema::create('stj_cupones', function (Blueprint $t) {
            $t->id('cup_id');
            $t->unsignedBigInteger('cup_header');
            $t->string('cup_codigo');
            $t->string('cup_estado');
            $t->dateTime('cup_fecha')->nullable();
            $t->integer('cup_vigencia')->nullable();
            $t->decimal('cup_monto')->nullable();
            $t->decimal('cup_descuento')->nullable();
            $t->string('cup_multiple')->nullable();
            $t->decimal('cup_disponible')->nullable();
            $t->string('cup_pais')->nullable();
            $t->string('cup_aplica_monto_minimo')->nullable();
            $t->decimal('cup_monto_minimo')->nullable();
            $t->string('cup_correo')->nullable();
            $t->boolean('cup_correo_enviado')->default(false);
        });
        Schema::create('stj_cupones_producto', function (Blueprint $t) {
            $t->id('cpr_id');
            $t->unsignedBigInteger('cpr_cupon');
            $t->unsignedBigInteger('cpr_producto');
            $t->decimal('cpr_descuento')->nullable();
            $t->decimal('cpr_precio')->nullable();
        });
    }
}
