<?php

namespace Tests\Feature;

use App\Services\AbandonedCartReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AbandonedCartReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $t) {
            $t->id('pai_id');
            $t->string('pai_nombre');
        });
        Schema::create('stj_usuarios', function (Blueprint $t) {
            $t->id('usu_id');
            $t->string('usu_nombre')->nullable();
            $t->string('usu_apellido')->nullable();
            $t->string('usu_correo')->nullable();
            $t->string('usu_telefono')->nullable();
        });
        Schema::create('stj_pedidos', function (Blueprint $t) {
            $t->id('ped_id');
            $t->unsignedBigInteger('ped_id_pais');
            $t->string('ped_estatus');
            $t->dateTime('ped_fecha');
            $t->string('ped_checkout');
            $t->string('ped_nombres')->nullable();
            $t->string('ped_apellidos')->nullable();
            $t->string('ped_email')->nullable();
            $t->string('ped_telefono')->nullable();
            $t->string('ped_origen')->nullable();
            $t->string('ped_plataforma')->nullable();
            $t->string('ped_vapp')->nullable();
        });
        Schema::create('stj_carritos', function (Blueprint $t) {
            $t->id('car_id');
            $t->uuid('car_uuid');
            $t->unsignedBigInteger('car_pais_id');
            $t->unsignedBigInteger('car_usu_id')->nullable();
            $t->unsignedBigInteger('car_pedido_id')->nullable();
            $t->string('car_tipo');
            $t->string('car_estado');
            $t->string('car_origen');
            $t->dateTime('car_ultima_actividad_en');
        });
        Schema::create('stj_productos', function (Blueprint $t) {
            $t->id('pro_id');
            $t->string('pro_codigo');
            $t->string('pro_nombre');
        });
        Schema::create('stj_carrito_detalles', function (Blueprint $t) {
            $t->id('cad_id');
            $t->unsignedBigInteger('cad_carrito_id');
            $t->unsignedBigInteger('cad_producto_id');
            $t->string('cad_ref');
            $t->string('cad_talla');
            $t->integer('cad_cantidad');
            $t->decimal('cad_precio_final_unitario');
            $t->string('cad_promocion')->nullable();
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $t) {
            $t->id('ppa_id');
            $t->unsignedBigInteger('ppa_pedido');
            $t->string('ppa_estado');
        });
        Schema::create('stj_powertranz_operaciones', function (Blueprint $t) {
            $t->id('pto_id');
            $t->unsignedBigInteger('pto_pago_id');
        });

        config()->set('abandoned_carts.to', ['operaciones@stjacks.com']);
        config()->set('abandoned_carts.cc', ['supervisor@stjacks.com']);
        config()->set('abandoned_carts.bcc', []);
        config()->set('abandoned_carts.timezone', 'America/El_Salvador');
        config()->set('abandoned_carts.lookback_hours', 24);
        config()->set('abandoned_carts.inactivity_minutes', 60);
        config()->set('services.smtp2go.key', 'test-key');
        config()->set('services.smtp2go.sender', 'no-reply@example.test');
        Http::fake(['*' => Http::response(['data' => ['failed' => 0]], 200)]);
    }

    public function test_it_reports_unapproved_checkout_and_inactive_cart_but_excludes_approved_and_recent_activity(): void
    {
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_nombre' => 'El Salvador']);
        DB::table('stj_usuarios')->insert(['usu_id' => 5, 'usu_nombre' => 'Ana', 'usu_apellido' => 'Cliente', 'usu_correo' => 'ana@example.com', 'usu_telefono' => '70000000']);
        DB::table('stj_productos')->insert(['pro_id' => 8, 'pro_codigo' => 'SKU8', 'pro_nombre' => 'Camisa']);
        DB::table('stj_pedidos')->insert([
            ['ped_id' => 10, 'ped_id_pais' => 1, 'ped_estatus' => 'PENDIENTE_PAGO', 'ped_fecha' => '2026-08-29 06:00:00', 'ped_checkout' => 'TIENDA', 'ped_nombres' => 'Ana', 'ped_apellidos' => 'Cliente', 'ped_email' => 'ana@example.com', 'ped_telefono' => '70000000', 'ped_origen' => 'APP', 'ped_plataforma' => 'IOS', 'ped_vapp' => '2.0'],
            ['ped_id' => 20, 'ped_id_pais' => 1, 'ped_estatus' => 'PENDIENTE_PAGO', 'ped_fecha' => '2026-08-29 06:30:00', 'ped_checkout' => 'TIENDA', 'ped_nombres' => 'Pago', 'ped_apellidos' => 'Aprobado', 'ped_email' => 'ok@example.com', 'ped_telefono' => '', 'ped_origen' => 'WEB', 'ped_plataforma' => null, 'ped_vapp' => null],
        ]);
        DB::table('stj_carritos')->insert([
            ['car_id' => 1, 'car_uuid' => '00000000-0000-0000-0000-000000000001', 'car_pais_id' => 1, 'car_usu_id' => 5, 'car_pedido_id' => 10, 'car_tipo' => 'TIENDA', 'car_estado' => 'CONVERTIDO', 'car_origen' => 'APP', 'car_ultima_actividad_en' => '2026-08-29 06:00:00'],
            ['car_id' => 2, 'car_uuid' => '00000000-0000-0000-0000-000000000002', 'car_pais_id' => 1, 'car_usu_id' => 5, 'car_pedido_id' => null, 'car_tipo' => 'DOMICILIO', 'car_estado' => 'ACTIVO', 'car_origen' => 'WEB', 'car_ultima_actividad_en' => '2026-08-29 05:00:00'],
            ['car_id' => 3, 'car_uuid' => '00000000-0000-0000-0000-000000000003', 'car_pais_id' => 1, 'car_usu_id' => 5, 'car_pedido_id' => null, 'car_tipo' => 'DOMICILIO', 'car_estado' => 'ACTIVO', 'car_origen' => 'WEB', 'car_ultima_actividad_en' => '2026-08-29 07:30:00'],
        ]);
        DB::table('stj_carrito_detalles')->insert([
            ['cad_carrito_id' => 1, 'cad_producto_id' => 8, 'cad_ref' => 'SKU8', 'cad_talla' => 'M', 'cad_cantidad' => 1, 'cad_precio_final_unitario' => 9.95, 'cad_promocion' => null],
            ['cad_carrito_id' => 2, 'cad_producto_id' => 8, 'cad_ref' => 'SKU8', 'cad_talla' => 'M', 'cad_cantidad' => 2, 'cad_precio_final_unitario' => 9.95, 'cad_promocion' => 'Oferta'],
            ['cad_carrito_id' => 3, 'cad_producto_id' => 8, 'cad_ref' => 'SKU8', 'cad_talla' => 'M', 'cad_cantidad' => 1, 'cad_precio_final_unitario' => 9.95, 'cad_promocion' => null],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_id' => 100, 'ppa_pedido' => 10, 'ppa_estado' => 'DENEGADA'],
            ['ppa_id' => 200, 'ppa_pedido' => 20, 'ppa_estado' => 'APROBADA'],
        ]);
        DB::table('stj_powertranz_operaciones')->insert(['pto_pago_id' => 100]);

        $summary = app(AbandonedCartReportService::class)->send(CarbonImmutable::parse('2026-08-29 08:00:00', 'America/El_Salvador'));

        $this->assertSame(1, $summary['payment_abandoned']);
        $this->assertSame(1, $summary['cart_abandoned']);
        Http::assertSent(function ($request): bool {
            $html = $request['html_body'];

            return $request['to'] === ['operaciones@stjacks.com']
                && $request['cc'] === ['supervisor@stjacks.com']
                && str_contains($html, 'Ana Cliente')
                && str_contains($html, 'DENEGADA')
                && str_contains($html, '3DS: SI')
                && str_contains($html, 'Carrito abandonado antes de crear pedido')
                && ! str_contains($html, 'Pago Aprobado');
        });
    }
}
