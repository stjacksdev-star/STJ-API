<?php

namespace Tests\Feature;

use App\Services\BirthdayCouponService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BirthdayCouponServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 00:05:00');
        Schema::create('stj_paises', fn (Blueprint $t) => [$t->id('pai_id'), $t->string('pai_codigo')]);
        Schema::create('stj_usuarios', function (Blueprint $t) {
            $t->id('usu_id'); $t->string('usu_nombre'); $t->string('usu_usuario'); $t->string('usu_correo')->nullable();
            $t->date('usu_fecha_nacimiento')->nullable(); $t->string('usu_tipo_login')->nullable();
            $t->unsignedBigInteger('usu_pais_registro')->nullable(); $t->boolean('usu_activo')->default(true);
        });
        Schema::create('stj_cupones_header', function (Blueprint $t) {
            $t->id('che_id');
            foreach (['che_aplica','che_tipo','che_checkout','che_generico','che_nombre','che_nombre_comercial','che_regional','che_aplica_monto_minimo','che_descuento_extra','che_multiple','che_aplica_promo','che_solo_primera_compra','che_estado','che_config_automatica','che_tipo_productos','che_para'] as $column) $t->string($column)->nullable();
            $t->unsignedBigInteger('che_pais'); $t->unsignedBigInteger('che_genero')->nullable(); $t->unsignedBigInteger('che_coleccion')->nullable();
            $t->dateTime('che_inicio')->nullable(); $t->dateTime('che_final')->nullable();
            $t->decimal('che_monto')->nullable(); $t->decimal('che_descuento')->nullable(); $t->decimal('che_monto_minimo')->nullable();
        });
        Schema::create('stj_cupones', function (Blueprint $t) {
            $t->id('cup_id'); $t->unsignedBigInteger('cup_header'); $t->string('cup_codigo'); $t->string('cup_estado');
            $t->dateTime('cup_fecha')->nullable(); $t->integer('cup_vigencia')->nullable(); $t->decimal('cup_monto')->nullable();
            $t->decimal('cup_descuento')->nullable(); $t->string('cup_multiple')->nullable(); $t->decimal('cup_disponible')->nullable();
            $t->string('cup_pais')->nullable(); $t->string('cup_aplica_monto_minimo')->nullable(); $t->decimal('cup_monto_minimo')->nullable();
            $t->string('cup_correo')->nullable(); $t->unsignedTinyInteger('cup_correo_enviado')->default(0);
        });
        DB::table('stj_paises')->insert([['pai_id' => 1, 'pai_codigo' => 'SV'], ['pai_id' => 2, 'pai_codigo' => 'HN']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_generates_one_annual_coupon_using_country_and_customer_channel(): void
    {
        $this->template(1, 1, 'APP');
        $this->template(2, 1, 'WEB');
        $this->template(3, 2, 'TODO');
        DB::table('stj_usuarios')->insert([
            $this->customer(1, 'app-sv@example.com', 'APP', 1),
            $this->customer(2, 'web-sv@example.com', 'WEB', 1),
            $this->customer(3, 'app-hn@example.com', 'APP', 2),
        ]);

        $first = app(BirthdayCouponService::class)->generateToday();
        $second = app(BirthdayCouponService::class)->generateToday();

        $this->assertSame(3, $first['generated']);
        $this->assertSame(0, $second['generated']);
        $this->assertSame(3, $second['duplicates']);
        $this->assertDatabaseCount('stj_cupones', 3);
        $this->assertDatabaseHas('stj_cupones_header', ['che_nombre' => 'CUMPLE', 'che_pais' => 1, 'che_aplica' => 'APP', 'che_para' => 'CUMPLE']);
        $this->assertDatabaseHas('stj_cupones_header', ['che_nombre' => 'CUMPLE', 'che_pais' => 1, 'che_aplica' => 'WEB', 'che_para' => 'CUMPLE']);
        $this->assertDatabaseHas('stj_cupones_header', ['che_nombre' => 'CUMPLE', 'che_pais' => 2, 'che_aplica' => 'TODO', 'che_para' => 'CUMPLE']);
    }

    public function test_it_does_not_use_another_country_or_wrong_channel_template(): void
    {
        $this->template(1, 1, 'WEB');
        DB::table('stj_usuarios')->insert([
            $this->customer(1, 'app-sv@example.com', 'APP', 1),
            $this->customer(2, 'web-hn@example.com', 'WEB', 2),
        ]);

        $summary = app(BirthdayCouponService::class)->generateToday();

        $this->assertSame(0, $summary['generated']);
        $this->assertSame(2, $summary['withoutTemplate']);
    }

    private function template(int $id, int $country, string $channel): void
    {
        DB::table('stj_cupones_header')->insert([
            'che_id' => $id, 'che_aplica' => $channel, 'che_tipo' => 'DESCUENTO', 'che_checkout' => 'TODO',
            'che_generico' => 'NO', 'che_nombre' => 'Plantilla cumpleaños', 'che_nombre_comercial' => 'Feliz cumpleaños',
            'che_regional' => 'NO', 'che_pais' => $country, 'che_inicio' => now()->subMonth(), 'che_final' => now()->addMonth(),
            'che_monto' => 0, 'che_descuento' => 20, 'che_aplica_monto_minimo' => 'NO', 'che_monto_minimo' => 0,
            'che_descuento_extra' => 'NO', 'che_multiple' => 'NO', 'che_aplica_promo' => 'REGULAR',
            'che_solo_primera_compra' => 'NO', 'che_estado' => 'ACTIVO', 'che_config_automatica' => 'CUMPLE',
            'che_tipo_productos' => 'NA', 'che_para' => 'NA',
        ]);
    }

    private function customer(int $id, string $email, string $channel, int $country): array
    {
        return ['usu_id' => $id, 'usu_nombre' => 'Cliente', 'usu_usuario' => $email, 'usu_correo' => $email,
            'usu_fecha_nacimiento' => '1990-09-14', 'usu_tipo_login' => $channel, 'usu_pais_registro' => $country, 'usu_activo' => 1];
    }
}
