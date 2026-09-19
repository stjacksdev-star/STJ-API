<?php

namespace Tests\Feature;

use App\Services\Dashboard\OrderReferenceService;
use App\Services\Mail\Smtp2GoMailer;
use App\Services\StorefrontOrderAmountSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StorefrontOrderAmountSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('stj_pedidos', function (Blueprint $t) {
            $t->id('ped_id');
            $t->integer('ped_id_pais');
            $t->string('ped_sesion');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $t) {
            $t->integer('ppa_pedido');
            $t->string('ppa_ref');
            $t->decimal('ppa_monto_senv', 12, 2);
        });
        Schema::create('stj_carritos', function (Blueprint $t) {
            $t->id('car_id');
            $t->string('car_uuid');
            $t->integer('car_pais_id');
        });
        Schema::create('stj_carrito_operaciones', function (Blueprint $t) {
            $t->id('cao_id');
            $t->integer('cao_carrito_id');
            $t->string('cao_tipo');
            $t->text('cao_respuesta');
        });
        DB::table('stj_pedidos')->insert(['ped_id' => 1, 'ped_id_pais' => 7, 'ped_sesion' => 'cart-uuid']);
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 1, 'ppa_ref' => 'NEW-950', 'ppa_monto_senv' => 950]);
        DB::table('stj_carritos')->insert(['car_id' => 1, 'car_uuid' => 'cart-uuid', 'car_pais_id' => 7]);
        $this->saveSnapshot();
    }

    public function test_new_order_uses_exact_amounts_in_both_dashboard_columns(): void
    {
        $product = $this->product();
        $this->assertSame(9.52, $product['discount']);
        $this->assertSame(950.0, $product['chargedSubtotal']);
        $this->assertSame(950.0, $product['billedSubtotal']);
        $this->assertSame([], app(StorefrontOrderAmountSnapshot::class)->lines('NEW-950', 1));
        $this->assertSame([], app(StorefrontOrderAmountSnapshot::class)->lines('OTHER', 7));
    }

    public function test_historical_order_without_version_keeps_its_existing_behavior(): void
    {
        $this->saveSnapshot(null);
        $this->assertSame(950.04, $this->product()['chargedSubtotal']);
        $this->assertSame(950.04, $this->product()['billedSubtotal']);
    }

    public function test_unverifiable_snapshot_does_not_hide_a_payment_difference(): void
    {
        DB::table('stj_pedidos_pago')->update(['ppa_monto_senv' => 940]);
        $this->assertSame([], app(StorefrontOrderAmountSnapshot::class)->lines('NEW-950', 7));
        $this->assertSame(950.04, $this->product()['chargedSubtotal']);
    }

    public function test_line_changes_use_current_terms_instead_of_the_original_amount(): void
    {
        foreach ([
            ['car_cantidad' => 1], ['car_precio' => 520], ['car_descuento' => 10],
            ['car_producto' => 11], ['pro_codigo' => 'OTHER'], ['car_talla' => '08'],
        ] as $changes) {
            $line = $this->line($changes);
            $this->assertNull(StorefrontOrderAmountSnapshot::subtotal($line, $this->snapshot()));
        }
        $this->assertSame(475.02, $this->product(['car_cantidad' => 1])['chargedSubtotal']);
        $this->assertSame(0.0, $this->product(['car_cantidad' => 0, 'car_total_facturado' => 0])['billedSubtotal']);
        $this->assertSame(945.0, $this->product(['car_descuento' => 10])['chargedSubtotal']);
    }

    public function test_billing_changes_do_not_reuse_the_original_billed_amount(): void
    {
        $product = $this->product(['car_total_facturado' => 1]);
        $this->assertSame(950.0, $product['chargedSubtotal']);
        $this->assertSame(475.02, $product['billedSubtotal']);
        $this->assertSame(945.0, $this->product(['car_descuento_final' => 10])['billedSubtotal']);
        $this->assertSame(950.04, $this->product(['car_estilo_final' => 'OTHER'])['billedSubtotal']);
        $this->assertSame(950.04, $this->product(['car_talla_final' => '08'])['billedSubtotal']);
        $this->assertSame(0.0, $this->product(['car_total_facturado' => null])['billedSubtotal']);
    }

    public function test_processing_preserves_exact_totals_and_marks_unchanged_order_complete(): void
    {
        $this->processingTables();
        $service = \Mockery::mock(OrderReferenceService::class, [\Mockery::mock(Smtp2GoMailer::class)])->makePartial();
        $service->shouldReceive('show')->once()->andReturn(['order' => ['customer' => ['email' => '']]]);
        $service->processOrder('NEW-950', '7', 'TICKET', null, ['name' => 'Test']);
        $this->assertDatabaseHas('stj_pedidos', ['ped_id' => 1, 'ped_estatus' => 'PREPARADO', 'ped_estatus_productos' => 'COMPLETO', 'ped_devolucion_realizada' => 'N/A']);
        $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_ref' => 'NEW-950', 'ppa_monto' => 950, 'ppa_monto_senv' => 950]);
        $products = (new \ReflectionMethod(OrderReferenceService::class, 'products'))->invoke($service, 'NEW-950', 7);
        $this->assertSame(950.0, $products[0]['chargedSubtotal']);
        $this->assertSame(950.0, $products[0]['billedSubtotal']);
        $order = (object) ['ped_id' => 1, 'ped_id_pais' => 7, 'ppa_id' => 1, 'ppa_ref' => 'NEW-950',
            'ppa_monto' => 950, 'ppa_monto_senv' => 950, 'ped_checkout' => 'TIENDA', 'pdi_id' => null, 'dir_id' => null];
        $summary = (new \ReflectionMethod(OrderReferenceService::class, 'normalizeOrder'))->invoke($service, $order, $products);
        $this->assertSame(0.0, $summary['totals']['productsDifference']);
        $this->assertSame(0.0, $summary['totals']['paidDifference']);
        $this->assertSame(950.0, $summary['totals']['billed']);
    }

    public function test_card_edit_validation_accepts_unchanged_terms_and_rejects_real_increase(): void
    {
        $this->processingTables();
        $line = $this->line(['ppa_tipo' => 'TARJETA', 'ppa_ref' => 'NEW-950', 'ped_id_pais' => 7, 'ped_checkout' => 'TIENDA', 'ppa_monto' => 950]);
        $service = (new \ReflectionClass(OrderReferenceService::class))->newInstanceWithoutConstructor();
        $validate = new \ReflectionMethod($service, 'ensureCardLineDoesNotIncreasePayment');
        $validate->invoke($service, $line, ['id' => 10, 'sku' => 'SKU10', 'price' => 525.0], '06', 2, 9.52);
        $this->assertSame(950.0, $this->product()['chargedSubtotal']);
        $this->expectException(ValidationException::class);
        $validate->invoke($service, $line, ['id' => 10, 'sku' => 'SKU10', 'price' => 526.0], '06', 2, 9.52);
    }

    private function processingTables(): void
    {
        Schema::table('stj_pedidos', function (Blueprint $t) {
            foreach (['ped_estatus', 'ped_estatus_productos', 'ped_checkout', 'ped_devolucion_realizada', 'ped_monto_devolucion', 'ped_fecha_devolucion', 'ped_fecha_devolucion_sistema', 'ped_observacion_devolucion', 'ped_a_usuario', 'ped_a_ip', 'ped_a_fecha'] as $column) {
                $t->string($column)->nullable();
            }
        });
        DB::table('stj_pedidos')->update(['ped_estatus' => 'RECIBIDO', 'ped_checkout' => 'TIENDA']);
        Schema::table('stj_pedidos_pago', function (Blueprint $t) {
            $t->integer('ppa_id')->default(1);
            $t->integer('ppa_articulos')->default(2);
            $t->decimal('ppa_monto', 12, 2)->default(950);
            $t->string('ppa_tipo')->default('TARJETA');
            $t->string('ppa_estado')->default('APROBADA');
            foreach (['ppa_ticket', 'ppa_fecha_procesado', 'ppa_a_usuario', 'ppa_a_ip', 'ppa_a_fecha'] as $column) {
                $t->string($column)->nullable();
            }
        });
        Schema::create('stj_paises', function (Blueprint $t) {
            $t->integer('pai_id');
        });
        DB::table('stj_paises')->insert(['pai_id' => 7]);
        Schema::create('stj_pedidos_direccion', function (Blueprint $t) {
            $t->integer('pdi_pedido');
            $t->decimal('pdi_costo_envio_final');
        });
        Schema::create('stj_pedidos_detalle_log', function (Blueprint $t) {
            $t->string('pdl_ref');
        });
        Schema::create('stj_productos', function (Blueprint $t) {
            $t->integer('pro_id');
            $t->string('pro_codigo');
            $t->string('pro_nombre');
        });
        DB::table('stj_productos')->insert(['pro_id' => 10, 'pro_codigo' => 'SKU10', 'pro_nombre' => 'Pantalon']);
        Schema::create('stj_producto_pais', function (Blueprint $t) {
            $t->integer('ppa_producto');
            $t->integer('ppa_pais');
            $t->decimal('ppa_precio');
        });
        DB::table('stj_producto_pais')->insert(['ppa_producto' => 10, 'ppa_pais' => 7, 'ppa_precio' => 525]);
        Schema::create('stj_pedidos_detalle', function (Blueprint $t) {
            foreach (['car_id', 'car_producto', 'car_cantidad', 'car_total_facturado', 'car_pais', 'car_promocion_id'] as $column) {
                $t->integer($column)->nullable();
            }
            foreach (['car_precio', 'car_descuento', 'car_descuento_final'] as $column) {
                $t->decimal($column, 12, 2)->nullable();
            }
            foreach (['car_talla', 'car_estilo_final', 'car_talla_final', 'car_ref', 'car_accion', 'car_a_usuario', 'car_a_ip', 'car_a_fecha'] as $column) {
                $t->string($column)->nullable();
            }
        });
        $line = (array) $this->line(['car_pais' => 7, 'car_ref' => 'NEW-950', 'car_accion' => 'AGREGADO']);
        unset($line['pro_codigo'], $line['pro_nombre']);
        DB::table('stj_pedidos_detalle')->insert($line);
    }

    private function saveSnapshot(?int $version = 1): void
    {
        DB::table('stj_carrito_operaciones')->updateOrInsert(['cao_id' => 1], [
            'cao_carrito_id' => 1, 'cao_tipo' => 'ORDER_CREATE',
            'cao_respuesta' => json_encode(['order' => ['pedidoId' => 1, 'paymentRef' => 'NEW-950', 'lineAmountsVersion' => $version, 'items' => [$this->snapshot()]]]),
        ]);
    }

    private function snapshot(): array
    {
        return ['detailId' => 100, 'productId' => 10, 'sku' => 'SKU10', 'size' => '06', 'quantity' => 2,
            'regularPrice' => '525.00', 'persistedDiscountPercentage' => 9.52, 'finalTotal' => '950.00'];
    }

    private function line(array $changes = []): object
    {
        return (object) [...[
            'car_id' => 100, 'car_producto' => 10, 'pro_codigo' => 'SKU10', 'pro_nombre' => 'Pantalon',
            'car_talla' => '06', 'car_cantidad' => 2, 'car_total_facturado' => 2, 'car_precio' => 525,
            'car_descuento' => 9.52, 'car_descuento_final' => 9.52,
            'car_estilo_final' => 'SKU10', 'car_talla_final' => '06', 'car_promocion_id' => 1,
        ], ...$changes];
    }

    private function product(array $changes = []): array
    {
        $snapshots = app(StorefrontOrderAmountSnapshot::class)->lines('NEW-950', 7);
        $service = (new \ReflectionClass(OrderReferenceService::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($service, 'normalizeProduct'))->invoke($service, $this->line($changes), $snapshots[100] ?? null);
    }
}
