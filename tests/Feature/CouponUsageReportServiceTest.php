<?php

namespace Tests\Feature;

use App\Services\Dashboard\CouponUsageReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CouponUsageReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_paises', fn (Blueprint $t) => [$t->id('pai_id'), $t->string('pai_codigo'), $t->string('pai_nombre')]);
        Schema::create('stj_pedidos', function (Blueprint $t) { $t->id('ped_id'); $t->unsignedBigInteger('ped_id_pais'); foreach (['ped_nombres', 'ped_apellidos', 'ped_email', 'ped_checkout'] as $column) $t->string($column)->nullable(); $t->dateTime('ped_fecha'); });
        Schema::create('stj_pedidos_pago', function (Blueprint $t) { $t->id('ppa_id'); $t->unsignedBigInteger('ppa_pedido'); $t->string('ppa_estado'); $t->string('ppa_ref'); $t->decimal('ppa_monto_sdesc'); $t->decimal('ppa_monto_senv'); });
        Schema::create('stj_cupones', function (Blueprint $t) { $t->id('cup_id'); $t->string('cup_correo')->nullable(); $t->decimal('cup_descuento')->nullable(); $t->decimal('cup_monto')->nullable(); });
        Schema::create('stj_pedido_cupones_aplicados', function (Blueprint $t) { $t->id('pca_id'); $t->unsignedBigInteger('pca_pedido_id'); $t->unsignedBigInteger('pca_cupon_id'); $t->string('pca_codigo'); $t->string('pca_nombre')->nullable(); $t->string('pca_tipo'); $t->decimal('pca_descuento_productos'); $t->decimal('pca_descuento_envio'); $t->decimal('pca_descuento_total'); $t->string('pca_estado'); $t->dateTime('pca_consumido_en')->nullable(); });

        DB::table('stj_paises')->insert([['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador'], ['pai_id' => 2, 'pai_codigo' => 'GT', 'pai_nombre' => 'Guatemala']]);
        DB::table('stj_pedidos')->insert([
            ['ped_id' => 10, 'ped_id_pais' => 1, 'ped_nombres' => 'Ana', 'ped_apellidos' => 'López', 'ped_email' => 'ana@example.com', 'ped_checkout' => 'DOMICILIO', 'ped_fecha' => '2026-08-10 10:00:00'],
            ['ped_id' => 11, 'ped_id_pais' => 1, 'ped_nombres' => 'Luis', 'ped_apellidos' => 'Pérez', 'ped_email' => 'luis@example.com', 'ped_checkout' => 'TIENDA', 'ped_fecha' => '2026-08-11 10:00:00'],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_id' => 20, 'ppa_pedido' => 10, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ-ANA', 'ppa_monto_sdesc' => 110, 'ppa_monto_senv' => 90],
            ['ppa_id' => 21, 'ppa_pedido' => 11, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ-LUIS', 'ppa_monto_sdesc' => 50, 'ppa_monto_senv' => 45],
        ]);
        DB::table('stj_cupones')->insert([
            ['cup_id' => 30, 'cup_correo' => 'ana@example.com', 'cup_descuento' => 20, 'cup_monto' => 0],
            ['cup_id' => 31, 'cup_correo' => 'luis@example.com', 'cup_descuento' => 0, 'cup_monto' => 10],
        ]);
        DB::table('stj_pedido_cupones_aplicados')->insert([
            ['pca_id' => 40, 'pca_pedido_id' => 10, 'pca_cupon_id' => 30, 'pca_codigo' => 'ANA20', 'pca_nombre' => 'Cupón Ana', 'pca_tipo' => 'DESCUENTO', 'pca_descuento_productos' => 20, 'pca_descuento_envio' => 0, 'pca_descuento_total' => 20, 'pca_estado' => 'CONSUMIDO', 'pca_consumido_en' => '2026-08-10 10:05:00'],
            ['pca_id' => 41, 'pca_pedido_id' => 11, 'pca_cupon_id' => 31, 'pca_codigo' => 'LUIS10', 'pca_nombre' => 'Cupón Luis', 'pca_tipo' => 'PRECIO', 'pca_descuento_productos' => 5, 'pca_descuento_envio' => 0, 'pca_descuento_total' => 5, 'pca_estado' => 'CONSUMIDO', 'pca_consumido_en' => '2026-08-11 10:05:00'],
        ]);
    }

    public function test_it_filters_used_coupons_by_customer_email_and_returns_totals(): void
    {
        $result = app(CouponUsageReportService::class)->report('SV', '2026-08-01', '2026-08-31', 'ana@example.com');

        $this->assertCount(1, $result['rows']);
        $this->assertSame('ANA20', $result['rows'][0]['code']);
        $this->assertSame('STJ-ANA', $result['rows'][0]['orderReference']);
        $this->assertSame(110.0, $result['rows'][0]['orderAmount']);
        $this->assertSame(90.0, $result['rows'][0]['orderFinalAmount']);
        $this->assertSame(20.0, $result['summary']['totalDiscount']);
    }

    public function test_it_can_find_a_consumed_coupon_by_code(): void
    {
        $result = app(CouponUsageReportService::class)->report('SV', '2026-08-01', '2026-08-31', 'LUIS10');

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('luis@example.com', $result['rows'][0]['customerEmail']);
    }
}
