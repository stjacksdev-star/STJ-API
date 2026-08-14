<?php

namespace Tests\Feature;

use App\Models\StorefrontVisitor;
use App\Services\StorefrontCartCouponService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StorefrontCartCouponServiceTest extends TestCase
{
    private StorefrontCartCouponService $service;

    private StorefrontVisitor $visitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->schema();
        $this->seedData();
        $this->visitor = new StorefrontVisitor;
        $this->visitor->setAttribute('vis_id', 10);
        $this->visitor->exists = true;
        $this->service = app(StorefrontCartCouponService::class);
    }

    public function test_add_persists_and_revalidates_coupon_idempotently(): void
    {
        $uuid = (string) Str::uuid();
        $input = ['operation_uuid' => $uuid, 'code' => ' welcome10 ', 'email' => ' CLIENT@example.com '];

        $first = $this->service->add('sv', $this->visitor, null, $input);
        $second = $this->service->add('sv', $this->visitor, null, $input);

        $this->assertSame('APLICADO', $first['applications'][0]['status']);
        $this->assertNull($first['applications'][0]['reasonCode']);
        $this->assertNull($first['applications'][0]['reason']);
        $this->assertSame('10.00', $first['totals']['couponDiscount']);
        $this->assertSame($first['applications'][0]['id'], $second['applications'][0]['id']);
        $this->assertSame(1, DB::table('stj_carrito_cupones')->count());
        $this->assertDatabaseHas('stj_carrito_cupones', ['ccu_estado' => 'APLICADO', 'ccu_carrito_version' => 3]);
    }

    public function test_personal_coupon_with_wrong_email_is_kept_as_not_applicable(): void
    {
        $result = $this->service->add('sv', $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(), 'code' => 'WELCOME10', 'email' => 'other@example.com',
        ]);

        $this->assertSame('NO_APLICABLE', $result['applications'][0]['status']);
        $this->assertSame('CORREO_NO_COINCIDE', $result['applications'][0]['reasonCode']);
        $this->assertSame('0.00', $result['totals']['couponDiscount']);
    }

    public function test_remove_closes_application_without_deleting_history(): void
    {
        $added = $this->service->add('sv', $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(), 'code' => 'WELCOME10', 'email' => 'client@example.com',
        ]);

        $result = $this->service->remove('sv', $added['applications'][0]['id'], $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(), 'email' => 'client@example.com',
        ]);

        $this->assertSame([], $result['coupons']);
        $this->assertDatabaseHas('stj_carrito_cupones', ['ccu_estado' => 'ELIMINADO', 'ccu_razon_codigo' => 'ELIMINADO_CLIENTE']);
    }

    public function test_consumed_non_multiple_coupon_is_rejected(): void
    {
        DB::table('stj_pedido_cupones_aplicados')->insert(['pca_cupon_id' => 1, 'pca_estado' => 'CONSUMIDO']);

        $this->expectException(ValidationException::class);
        $this->service->add('sv', $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(), 'code' => 'WELCOME10', 'email' => 'client@example.com',
        ]);
    }

    private function seedData(): void
    {
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_carritos')->insert([
            'car_id' => 1, 'car_visitante_id' => 10, 'car_usu_id' => null, 'car_pais_id' => 1, 'car_estado' => 'ACTIVO',
            'car_tipo' => 'DOMICILIO', 'car_tienda_id' => 1, 'car_tienda_codigo_snapshot' => 'WEB', 'car_version' => 3,
        ]);
        DB::table('stj_carrito_detalles')->insert([
            'cad_id' => 1, 'cad_carrito_id' => 1, 'cad_producto_id' => 100, 'cad_cantidad' => 1,
            'cad_precio_unitario' => 100, 'cad_seleccionado' => true, 'cad_estado' => 'DISPONIBLE',
        ]);
        DB::table('stj_cupones_header')->insert([
            'che_id' => 1, 'che_nombre' => 'Bienvenida', 'che_tipo' => 'DESCUENTO', 'che_aplica' => 'WEB',
            'che_checkout' => 'TODO', 'che_generico' => 'NO', 'che_pais' => 1, 'che_inicio' => '2026-01-01',
            'che_final' => '2027-01-01', 'che_monto' => 0, 'che_descuento' => 10, 'che_aplica_monto_minimo' => 'NO',
            'che_monto_minimo' => 0, 'che_multiple' => 'NO', 'che_aplica_promo' => 'REGULAR',
            'che_solo_primera_compra' => 'NO', 'che_estado' => 'ACTIVO', 'che_tipo_productos' => 'NA',
        ]);
        DB::table('stj_cupones')->insert([
            'cup_id' => 1, 'cup_header' => 1, 'cup_codigo' => 'WELCOME10', 'cup_estado' => 'ACTIVO',
            'cup_monto' => 0, 'cup_descuento' => 10, 'cup_correo' => 'client@example.com',
        ]);
    }

    private function schema(): void
    {
        Schema::create('stj_paises', fn (Blueprint $t) => [$t->id('pai_id'), $t->string('pai_codigo')]);
        Schema::create('stj_carritos', function (Blueprint $t) {
            $t->id('car_id');
            $t->unsignedBigInteger('car_visitante_id');
            $t->unsignedBigInteger('car_usu_id')->nullable();
            $t->unsignedBigInteger('car_pais_id');
            $t->string('car_estado');
            $t->string('car_tipo');
            $t->unsignedBigInteger('car_tienda_id')->nullable();
            $t->string('car_tienda_codigo_snapshot')->nullable();
            $t->unsignedBigInteger('car_version');
        });
        Schema::create('stj_carrito_detalles', function (Blueprint $t) {
            $t->id('cad_id');
            $t->unsignedBigInteger('cad_carrito_id');
            $t->unsignedBigInteger('cad_producto_id');
            $t->integer('cad_cantidad');
            $t->decimal('cad_precio_unitario');
            $t->boolean('cad_seleccionado');
            $t->string('cad_estado');
        });
        Schema::create('stj_cupones_header', function (Blueprint $t) {
            $t->id('che_id');
            $t->string('che_nombre');
            $t->string('che_tipo');
            $t->string('che_aplica');
            $t->string('che_checkout');
            $t->string('che_generico');
            $t->unsignedBigInteger('che_pais');
            $t->dateTime('che_inicio')->nullable();
            $t->dateTime('che_final')->nullable();
            $t->decimal('che_monto')->nullable();
            $t->decimal('che_descuento')->nullable();
            $t->string('che_aplica_monto_minimo')->nullable();
            $t->decimal('che_monto_minimo')->nullable();
            $t->string('che_multiple')->nullable();
            $t->string('che_aplica_promo')->nullable();
            $t->string('che_solo_primera_compra')->nullable();
            $t->string('che_estado');
            $t->string('che_tipo_productos')->nullable();
        });
        Schema::create('stj_cupones', function (Blueprint $t) {
            $t->id('cup_id');
            $t->unsignedBigInteger('cup_header');
            $t->string('cup_codigo');
            $t->string('cup_estado');
            $t->decimal('cup_monto')->nullable();
            $t->decimal('cup_descuento')->nullable();
            $t->string('cup_correo')->nullable();
        });
        Schema::create('stj_cupones_producto', function (Blueprint $t) {
            $t->id('cpr_id');
            $t->unsignedBigInteger('cpr_cupon');
            $t->unsignedBigInteger('cpr_producto');
            $t->decimal('cpr_descuento')->nullable();
            $t->decimal('cpr_precio')->nullable();
        });
        Schema::create('stj_carrito_cupones', function (Blueprint $t) {
            $t->id('ccu_id');
            $t->unsignedBigInteger('ccu_carrito_id');
            $t->unsignedBigInteger('ccu_cupon_id');
            $t->string('ccu_codigo');
            $t->string('ccu_estado');
            $t->string('ccu_razon_codigo')->nullable();
            $t->string('ccu_razon_mensaje')->nullable();
            $t->unsignedBigInteger('ccu_carrito_version');
            $t->string('ccu_correo_snapshot')->nullable();
            $t->unsignedBigInteger('ccu_pais_id');
            $t->string('ccu_checkout_snapshot');
            $t->decimal('ccu_descuento_productos')->default(0);
            $t->decimal('ccu_descuento_envio')->default(0);
            $t->uuid('ccu_operation_uuid')->nullable()->unique();
            $t->dateTime('ccu_agregado_en');
            $t->dateTime('ccu_validado_en')->nullable();
            $t->dateTime('ccu_eliminado_en')->nullable();
            $t->dateTime('ccu_consumido_en')->nullable();
            $t->dateTime('ccu_creado_en');
            $t->dateTime('ccu_actualizado_en');
        });
        Schema::create('stj_pedido_cupones_aplicados', function (Blueprint $t) {
            $t->id('pca_id');
            $t->unsignedBigInteger('pca_cupon_id');
            $t->string('pca_estado');
        });
        Schema::create('stj_promociones', function (Blueprint $t) {
            $t->id('prm_id');
            $t->unsignedBigInteger('prm_pais');
            $t->string('prm_origen');
            $t->string('prm_nombre');
            $t->string('prm_nombre_comercial')->nullable();
            $t->string('prm_modalidad');
            $t->string('prm_tipo');
            $t->string('prm_estado');
            $t->string('prm_tipo_promocion');
            $t->string('prm_restriccion')->nullable();
            $t->decimal('prm_porcentaje')->nullable();
            $t->decimal('prm_precio')->nullable();
            $t->string('prm_tipo_checkout')->nullable();
            $t->string('prm_alcance_tienda')->nullable();
            $t->string('prm_aplica')->nullable();
        });
        Schema::create('stj_promociones_horario', function (Blueprint $t) {
            $t->id('pho_id');
            $t->string('pho_tipo');
            $t->unsignedBigInteger('pho_promocion');
            $t->dateTime('pho_inicio');
            $t->dateTime('pho_fin');
            $t->string('pho_estado');
        });
        Schema::create('stj_promociones_producto', function (Blueprint $t) {
            $t->id('ppr_id');
            $t->unsignedBigInteger('ppr_promocion');
            $t->unsignedBigInteger('ppr_producto');
            $t->decimal('ppr_descuento')->nullable();
            $t->decimal('ppr_precio')->nullable();
        });
        Schema::create('stj_promociones_tienda', function (Blueprint $t) {
            $t->id('prt_id');
            $t->unsignedBigInteger('prt_promocion');
            $t->unsignedBigInteger('prt_tienda');
        });
    }
}
