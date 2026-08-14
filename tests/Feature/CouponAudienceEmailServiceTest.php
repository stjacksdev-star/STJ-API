<?php

namespace Tests\Feature;

use App\Services\CouponAudienceEmailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CouponAudienceEmailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_paises', fn (Blueprint $t) => [$t->id('pai_id'), $t->string('pai_codigo')]);
        Schema::create('stj_cupones_header', function (Blueprint $t) { $t->id('che_id'); foreach (['che_para','che_estado','che_nombre','che_nombre_comercial','che_tipo','che_aplica','che_checkout','che_aplica_promo','che_aplica_monto_minimo','che_solo_primera_compra','che_tipo_productos','che_multiple'] as $c) $t->string($c)->nullable(); $t->decimal('che_descuento')->nullable(); $t->decimal('che_monto')->nullable(); $t->decimal('che_monto_minimo')->nullable(); $t->dateTime('che_inicio')->nullable(); $t->dateTime('che_final')->nullable(); $t->unsignedBigInteger('che_pais'); });
        Schema::create('stj_cupones', function (Blueprint $t) { $t->id('cup_id'); $t->unsignedBigInteger('cup_header'); $t->string('cup_codigo'); $t->string('cup_correo')->nullable(); $t->string('cup_estado'); $t->decimal('cup_descuento')->nullable(); $t->decimal('cup_monto')->nullable(); $t->unsignedTinyInteger('cup_correo_enviado')->default(0); });
        config()->set('services.smtp2go.url', 'https://smtp.test/send'); config()->set('services.smtp2go.key', 'key'); config()->set('services.smtp2go.sender', 'test@example.com');
        config()->set('services.fcm.web_home_url', 'http://localhost/stj-ecommerce/public/sv');
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_cupones_header')->insert(['che_id' => 1, 'che_para' => 'VIP', 'che_estado' => 'ACTIVO', 'che_nombre' => 'VIP', 'che_nombre_comercial' => 'Cupón VIP', 'che_tipo' => 'DESCUENTO', 'che_descuento' => 20, 'che_monto' => 0, 'che_inicio' => now()->subDay(), 'che_final' => now()->addDay(), 'che_pais' => 1, 'che_aplica' => 'WEB', 'che_checkout' => 'DOMICILIO', 'che_aplica_promo' => 'REGULAR', 'che_aplica_monto_minimo' => 'SI', 'che_monto_minimo' => 25, 'che_solo_primera_compra' => 'NO', 'che_tipo_productos' => 'PLA', 'che_multiple' => 'NO']);
        DB::table('stj_cupones')->insert(['cup_id' => 1, 'cup_header' => 1, 'cup_codigo' => 'VIP20', 'cup_correo' => 'vip@example.com', 'cup_estado' => 'ACTIVO', 'cup_descuento' => 20, 'cup_monto' => 0, 'cup_correo_enviado' => 0]);
    }

    public function test_it_sends_pending_personal_coupon_only_once(): void
    {
        Http::fake(['*' => Http::response(['data' => ['failed' => 0, 'succeeded' => 1]])]);
        $service = app(CouponAudienceEmailService::class);
        $first = $service->sendPending(); $second = $service->sendPending();
        $this->assertSame(1, $first['sent']); $this->assertSame(0, $second['sent']);
        $this->assertDatabaseHas('stj_cupones', ['cup_id' => 1, 'cup_correo_enviado' => 1]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['html_body'], 'Condiciones del cupón') && str_contains($request['html_body'], 'precio regular') && str_contains($request['html_body'], 'Compra mínima requerida: USD 25') && str_contains($request['html_body'], 'http://localhost/stj-ecommerce/public/sv/cupones/1/cupon-vip') && ! str_contains($request['html_body'], '/sv/sv/'));
    }

    public function test_it_returns_coupon_to_pending_when_delivery_fails(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $summary = app(CouponAudienceEmailService::class)->sendPending();
        $this->assertSame(1, $summary['failed']);
        $this->assertDatabaseHas('stj_cupones', ['cup_id' => 1, 'cup_correo_enviado' => 0]);
    }
}
