<?php

namespace Tests\Feature;

use App\Services\StorefrontCouponOrderLifecycleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontCouponOrderLifecycleServiceTest extends TestCase
{
    private StorefrontCouponOrderLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->schema();
        $this->service = app(StorefrontCouponOrderLifecycleService::class);
    }

    public function test_snapshot_does_not_consume_until_payment_is_approved(): void
    {
        $this->seedCoupon('NO');
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 50, 'ppa_estado' => 'PENDIENTE']);

        $this->service->snapshot(10, 50);
        $this->service->consumeApprovedOrder(50);

        $this->assertDatabaseHas('stj_pedido_cupones_aplicados', ['pca_pedido_id' => 50, 'pca_estado' => 'APLICADO']);
        $this->assertDatabaseHas('stj_carrito_cupones', ['ccu_id' => 5, 'ccu_estado' => 'APLICADO']);
        $this->assertDatabaseHas('stj_cupones', ['cup_id' => 1, 'cup_estado' => 'ACTIVO']);
    }

    public function test_approved_payment_consumes_non_multiple_coupon_idempotently(): void
    {
        $this->seedCoupon('NO');
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 50, 'ppa_estado' => 'APROBADA']);
        $this->service->snapshot(10, 50);

        $this->service->consumeApprovedOrder(50);
        $this->service->consumeApprovedOrder(50);

        $this->assertDatabaseCount('stj_pedido_cupones_aplicados', 1);
        $this->assertDatabaseHas('stj_pedido_cupones_aplicados', ['pca_estado' => 'CONSUMIDO']);
        $this->assertDatabaseHas('stj_carrito_cupones', ['ccu_estado' => 'CONSUMIDO']);
        $this->assertDatabaseHas('stj_cupones', ['cup_estado' => 'USADO', 'cup_disponible' => 0]);
    }

    public function test_multiple_coupon_keeps_code_active_after_approved_order(): void
    {
        $this->seedCoupon('SI');
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 50, 'ppa_estado' => 'APROBADA']);
        $this->service->snapshot(10, 50);
        $this->service->consumeApprovedOrder(50);

        $this->assertDatabaseHas('stj_pedido_cupones_aplicados', ['pca_estado' => 'CONSUMIDO']);
        $this->assertDatabaseHas('stj_cupones', ['cup_estado' => 'ACTIVO']);
    }

    public function test_generic_non_multiple_coupon_cannot_be_consumed_twice_by_same_email(): void
    {
        $this->seedCoupon('NO');
        DB::table('stj_cupones_header')->where('che_id', 2)->update(['che_generico' => 'SI']);
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 50, 'ppa_estado' => 'APROBADA']);
        $this->service->snapshot(10, 50);
        $this->service->consumeApprovedOrder(50);

        DB::table('stj_carrito_cupones')->insert([
            'ccu_id' => 6, 'ccu_carrito_id' => 11, 'ccu_cupon_id' => 1, 'ccu_codigo' => 'CODE', 'ccu_estado' => 'APLICADO',
            'ccu_carrito_version' => 1, 'ccu_correo_snapshot' => 'CLIENT@example.com', 'ccu_checkout_snapshot' => 'TIENDA',
            'ccu_descuento_productos' => 3, 'ccu_descuento_envio' => 0, 'ccu_actualizado_en' => now(),
        ]);
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 51, 'ppa_estado' => 'APROBADA']);
        $this->service->snapshot(11, 51);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->consumeApprovedOrder(51);
    }

    public function test_failed_payment_reverses_application_without_consuming_code(): void
    {
        $this->seedCoupon('NO');
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 50, 'ppa_estado' => 'DENEGADA']);
        $this->service->snapshot(10, 50);
        $this->service->closeUnapprovedOrder(50, 'DENEGADA');

        $this->assertDatabaseHas('stj_pedido_cupones_aplicados', ['pca_estado' => 'REVERSADO']);
        $this->assertDatabaseHas('stj_carrito_cupones', ['ccu_estado' => 'ELIMINADO', 'ccu_razon_codigo' => 'PAGO_DENEGADA']);
        $this->assertDatabaseHas('stj_cupones', ['cup_estado' => 'ACTIVO']);
    }

    private function seedCoupon(string $multiple): void
    {
        DB::table('stj_cupones_header')->insert([
            'che_id' => 2, 'che_nombre' => 'Cupón', 'che_tipo' => 'DESCUENTO', 'che_aplica' => 'WEB',
            'che_checkout' => 'TODO', 'che_pais' => 1, 'che_generico' => 'NO', 'che_multiple' => $multiple,
            'che_aplica_promo' => 'REGULAR', 'che_tipo_productos' => 'NA', 'che_aplica_monto_minimo' => 'NO',
            'che_monto_minimo' => 0, 'che_solo_primera_compra' => 'NO',
        ]);
        DB::table('stj_cupones')->insert(['cup_id' => 1, 'cup_header' => 2, 'cup_estado' => 'ACTIVO', 'cup_disponible' => 10]);
        DB::table('stj_carrito_cupones')->insert([
            'ccu_id' => 5, 'ccu_carrito_id' => 10, 'ccu_cupon_id' => 1, 'ccu_codigo' => 'CODE', 'ccu_estado' => 'APLICADO',
            'ccu_carrito_version' => 4, 'ccu_correo_snapshot' => 'client@example.com', 'ccu_checkout_snapshot' => 'DOMICILIO',
            'ccu_descuento_productos' => 5, 'ccu_descuento_envio' => 2, 'ccu_actualizado_en' => now(),
        ]);
    }

    private function schema(): void
    {
        Schema::create('stj_cupones_header', function (Blueprint $t) {
            $t->id('che_id');
            $t->string('che_nombre');
            $t->string('che_tipo');
            $t->string('che_aplica');
            $t->string('che_checkout');
            $t->unsignedBigInteger('che_pais');
            $t->string('che_generico');
            $t->string('che_multiple')->nullable();
            $t->string('che_aplica_promo');
            $t->string('che_tipo_productos');
            $t->string('che_aplica_monto_minimo');
            $t->decimal('che_monto_minimo');
            $t->string('che_solo_primera_compra');
        });
        Schema::create('stj_cupones', function (Blueprint $t) {
            $t->id('cup_id');
            $t->unsignedBigInteger('cup_header');
            $t->string('cup_estado');
            $t->decimal('cup_disponible')->nullable();
            $t->dateTime('cup_fecha_utilizado')->nullable();
        });
        Schema::create('stj_carrito_cupones', function (Blueprint $t) {
            $t->id('ccu_id');
            $t->unsignedBigInteger('ccu_carrito_id');
            $t->unsignedBigInteger('ccu_cupon_id');
            $t->string('ccu_codigo');
            $t->string('ccu_estado');
            $t->unsignedBigInteger('ccu_carrito_version');
            $t->string('ccu_correo_snapshot')->nullable();
            $t->string('ccu_checkout_snapshot');
            $t->decimal('ccu_descuento_productos');
            $t->decimal('ccu_descuento_envio');
            $t->dateTime('ccu_consumido_en')->nullable();
            $t->string('ccu_razon_codigo')->nullable();
            $t->string('ccu_razon_mensaje')->nullable();
            $t->dateTime('ccu_eliminado_en')->nullable();
            $t->dateTime('ccu_actualizado_en');
        });
        Schema::create('stj_pedido_cupones_aplicados', function (Blueprint $t) {
            $t->id('pca_id');
            $t->unsignedBigInteger('pca_pedido_id');
            $t->unsignedBigInteger('pca_carrito_cupon_id')->nullable();
            $t->unsignedBigInteger('pca_cupon_id');
            $t->unsignedBigInteger('pca_header_id');
            $t->string('pca_codigo');
            $t->string('pca_nombre')->nullable();
            $t->string('pca_tipo');
            $t->decimal('pca_descuento_productos');
            $t->decimal('pca_descuento_envio');
            $t->decimal('pca_descuento_total');
            $t->json('pca_regla_snapshot')->nullable();
            $t->json('pca_aplicacion_snapshot')->nullable();
            $t->string('pca_estado');
            $t->dateTime('pca_creado_en');
            $t->dateTime('pca_consumido_en')->nullable();
            $t->dateTime('pca_reversado_en')->nullable();
            $t->unique(['pca_pedido_id', 'pca_cupon_id']);
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $t) {
            $t->id('ppa_id');
            $t->unsignedBigInteger('ppa_pedido');
            $t->string('ppa_estado');
        });
    }
}
