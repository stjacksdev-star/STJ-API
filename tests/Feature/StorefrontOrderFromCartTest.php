<?php

namespace Tests\Feature;

use App\Models\StorefrontCart;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontCheckoutValidationService;
use App\Services\StorefrontOrderService;
use App\Services\StorefrontPaymentEventService;
use App\Services\StorefrontProductPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontOrderFromCartTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('fulfillmentCases')]
    public function test_order_uses_exact_authorized_store_code(string $countryCode, int $countryId, string $type, string $storeCode): void
    {
        $this->schema();
        DB::table('stj_paises')->insert(['pai_id' => $countryId, 'pai_codigo' => strtoupper($countryCode)]);
        DB::table('stj_tiendas')->insert(['tie_id' => 8, 'tie_pais' => $countryId, 'tie_codigo' => $storeCode, 'tie_nombre' => 'Tienda autorizada']);
        DB::table('stj_productos')->insert(['pro_id' => 10, 'pro_codigo' => 'SKU10', 'pro_nombre' => 'Producto', 'pro_tallas' => 'S', 'pro_estatus' => 'ACTIVO']);
        DB::table('stj_producto_pais')->insert(['ppa_pais' => $countryId, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 25, 'ppa_precio_talla' => 'NO', 'ppa_descuento' => 0]);
        $visitor = StorefrontVisitor::query()->create(['vis_uuid' => (string) Str::uuid(), 'vis_origen' => 'WEB', 'vis_pais_id' => $countryId, 'vis_primera_visita' => now(), 'vis_ultima_visita' => now(), 'vis_expira_en' => now()->addYear(), 'vis_creado_en' => now(), 'vis_actualizado_en' => now()]);
        $cart = StorefrontCart::query()->create(['car_uuid' => (string) Str::uuid(), 'car_visitante_id' => $visitor->getKey(), 'car_pais_id' => $countryId, 'car_tipo' => $type, 'car_tienda_id' => 8, 'car_tienda_codigo_snapshot' => $storeCode, 'car_inventory_source' => 'external_api', 'car_estado' => 'CHECKOUT', 'car_origen' => 'WEB', 'car_moneda' => 'USD', 'car_version' => 2, 'car_ultima_actividad_en' => now(), 'car_expira_en' => now()->addMonth(), 'car_checkout_en' => now(), 'car_creado_en' => now(), 'car_actualizado_en' => now()]);
        DB::table('stj_carrito_detalles')->insert(['cad_carrito_id' => $cart->getKey(), 'cad_producto_id' => 10, 'cad_talla' => 'S', 'cad_ref' => 'SKU10', 'cad_cantidad' => 2, 'cad_precio_unitario' => 1, 'cad_descuento_unitario' => 0, 'cad_precio_final_unitario' => 1, 'cad_seleccionado' => 1, 'cad_estado' => 'DISPONIBLE', 'cad_creado_en' => now(), 'cad_actualizado_en' => now()]);
        config(["inventory.domicilio_store_by_country.{$countryCode}" => $storeCode]);
        $validator = Mockery::mock(StorefrontCheckoutValidationService::class);
        $validator->shouldReceive('validate')->twice()->andReturn(['ok' => true]);
        $service = new StorefrontOrderService($validator, new StorefrontProductPricingService);
        $payload = ['operation_uuid' => (string) Str::uuid(), 'customer' => ['firstName' => 'Ana', 'lastName' => 'Lopez', 'email' => 'ana@example.com', 'phone' => '9999', 'document' => 'ID'], 'delivery' => ['city' => 'SPS', 'addressLine1' => 'Direccion'], 'items' => [['price' => 0.01]], 'guestCartId' => 'falso'];

        $first = $service->createFromCart($countryCode, $visitor, null, $payload);
        $retry = $service->createFromCart($countryCode, $visitor, null, $payload);

        $this->assertSame($first, $retry);
        $this->assertDatabaseHas('stj_pedidos', ['ped_id' => $first['order']['pedidoId'], 'ped_tienda' => $storeCode]);
        $relation = $type === 'DOMICILIO' ? 'stj_pedidos_direccion' : 'stj_pedidos_tienda';
        $foreign = $type === 'DOMICILIO' ? 'pdi_pedido' : 'pti_pedido';
        $this->assertDatabaseHas($relation, [$foreign => $first['order']['pedidoId']]);
        $this->assertDatabaseHas('stj_carritos', ['car_id' => $cart->getKey(), 'car_estado' => 'CONVERTIDO', 'car_pedido_id' => $first['order']['pedidoId']]);
        $this->assertDatabaseHas('stj_pedidos_detalle', ['car_precio' => 25, 'car_cantidad' => 2]);
        $this->assertSame(1, DB::table('stj_cliente_eventos')->where('cev_tipo', 'ORDER_CREATED')->count());
        $payments = new StorefrontPaymentEventService;
        $payments->record((int) $first['order']['pagoId'], 'DENEGADA', (string) Str::uuid());
        $this->assertSame(0, DB::table('stj_cliente_eventos')->where('cev_tipo', 'PURCHASE')->count());
        $payments->record((int) $first['order']['pagoId'], 'APROBADA', (string) Str::uuid());
        $payments->record((int) $first['order']['pagoId'], 'APROBADA', (string) Str::uuid());
        $payments->record((int) $first['order']['pagoId'], 'DENEGADA', (string) Str::uuid());
        $this->assertSame(1, DB::table('stj_cliente_eventos')->where('cev_tipo', 'PURCHASE')->count());
        $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_id' => $first['order']['pagoId'], 'ppa_estado' => 'APROBADA']);
    }

    public static function fulfillmentCases(): array
    {
        return [
            'domicilio SV' => ['sv', 1, 'DOMICILIO', '57'],
            'domicilio GT' => ['gt', 2, 'DOMICILIO', '2'],
            'domicilio CR' => ['cr', 3, 'DOMICILIO', '1'],
            'domicilio PA' => ['pa', 4, 'DOMICILIO', '1'],
            'domicilio HN conserva 001' => ['hn', 5, 'DOMICILIO', '001'],
            'retiro conserva 001' => ['sv', 1, 'TIENDA', '001'],
        ];
    }

    private function schema(): void
    {
        Schema::create('stj_paises', fn (Blueprint $t) => tap($t->bigInteger('pai_id', true), fn () => $t->string('pai_codigo', 3)));
        Schema::create('stj_tiendas', fn (Blueprint $t) => tap($t->bigInteger('tie_id', true), function () use ($t) {
            $t->bigInteger('tie_pais');
            $t->string('tie_codigo', 15);
            $t->string('tie_nombre');
        }));
        Schema::create('stj_productos', fn (Blueprint $t) => tap($t->bigInteger('pro_id', true), function () use ($t) {
            $t->string('pro_codigo');
            $t->string('pro_nombre');
            $t->string('pro_tallas');
            $t->string('pro_estatus');
        }));
        Schema::create('stj_producto_pais', fn (Blueprint $t) => tap($t->bigInteger('ppa_id', true), function () use ($t) {
            $t->bigInteger('ppa_pais');
            $t->bigInteger('ppa_producto');
            $t->string('ppa_estado');
            $t->decimal('ppa_precio', 12, 2);
            $t->string('ppa_precio_talla');
            $t->decimal('ppa_descuento', 12, 2);
            $t->string('ppa_origen_descuento')->nullable();
            $t->string('ppa_promo_nombre')->nullable();
        }));
        Schema::create('stj_producto_talla', fn (Blueprint $t) => tap($t->bigInteger('pta_id', true), function () use ($t) {
            $t->bigInteger('pta_pais');
            $t->bigInteger('pta_producto');
            $t->string('pta_talla');
            $t->decimal('pta_precio', 12, 2);
        }));
        Schema::create('stj_usuarios', fn (Blueprint $t) => $t->bigInteger('usu_id', true));
        Schema::create('stj_promociones', fn (Blueprint $t) => $t->bigInteger('prm_id', true));
        Schema::create('stj_pedidos', function (Blueprint $t) {
            $t->bigInteger('ped_id', true);
            foreach (['ped_id_pais', 'ped_user', 'ped_a_version'] as $c) {
                $t->bigInteger($c)->nullable();
            } foreach (['ped_origen', 'ped_estatus', 'ped_estatus_productos', 'ped_checkout', 'ped_tienda', 'ped_login', 'ped_sesion', 'ped_nombres', 'ped_apellidos', 'ped_email', 'ped_tipo_identificacion', 'ped_identificacion', 'ped_rtu', 'ped_pais', 'ped_departamento', 'ped_municipio', 'ped_estado', 'ped_ciudad', 'ped_direccion', 'ped_telefono_pais', 'ped_telefono', 'ped_whatsapp_pais', 'ped_whatsapp', 'ped_devolucion_realizada', 'ped_rsp_servicio', 'ped_monto_devolucion', 'ped_correo_enviado', 'ped_a_usuario', 'ped_a_ip', 'ped_a_generales', 'ped_credito_fiscal', 'ped_vapp', 'ped_suscrito_mailing'] as $c) {
                $t->string($c)->nullable();
            } $t->dateTime('ped_fecha')->nullable();
            $t->dateTime('ped_a_fecha')->nullable();
        });
        Schema::create('stj_direcciones', function (Blueprint $t) {
            $t->bigInteger('dir_id', true);
            foreach (['dir_tipo', 'dir_misma_persona', 'dir_misma_direccion', 'dir_usuario', 'dir_pais', 'dir_direccion', 'dir_referencia', 'dir_departamento_txt', 'dir_municipio_txt', 'dir_persona', 'dir_telefono', 'dir_save', 'dir_a_usuario', 'dir_a_ip'] as $c) {
                $t->string($c)->nullable();
            } $t->dateTime('dir_fecha')->nullable();
            $t->dateTime('dir_a_fecha')->nullable();
            $t->integer('dir_a_version')->nullable();
        });
        Schema::create('stj_pedidos_direccion', function (Blueprint $t) {
            $t->bigInteger('pdi_id', true);
            foreach (['pdi_pedido', 'pdi_direccion', 'pdi_a_version'] as $c) {
                $t->bigInteger($c)->nullable();
            } foreach (['pdi_tipo_envio', 'pdi_id_urbano', 'pdi_id_shipping', 'pdi_costo_envio', 'pdi_costo_envio_txt', 'pdi_costo_envio_final', 'pdi_aplica_envio_gratis', 'pdi_a_usuario', 'pdi_a_ip'] as $c) {
                $t->string($c)->nullable();
            } $t->dateTime('pdi_a_fecha')->nullable();
        });
        Schema::create('stj_pedidos_tienda', function (Blueprint $t) {
            $t->bigInteger('pti_id', true);
            foreach (['pti_pedido', 'pti_a_version'] as $c) {
                $t->bigInteger($c)->nullable();
            } foreach (['pti_misma_persona', 'pti_pais', 'pti_tienda', 'pti_persona', 'pti_telefono', 'pti_identificacion', 'pti_a_usuario', 'pti_a_ip'] as $c) {
                $t->string($c)->nullable();
            } $t->dateTime('pti_a_fecha')->nullable();
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $t) {
            $t->bigInteger('ppa_id', true);
            foreach (['ppa_pedido', 'ppa_articulos', 'ppa_a_version'] as $c) {
                $t->bigInteger($c)->nullable();
            } foreach (['ppa_tipo', 'ppa_estado', 'ppa_ref', 'ppa_emisor', 'ppa_tarjeta', 'ppa_pagado', 'ppa_a_usuario', 'ppa_a_ip'] as $c) {
                $t->string($c)->nullable();
            } foreach (['ppa_monto_sdesc', 'ppa_monto_senv', 'ppa_monto'] as $c) {
                $t->decimal($c, 12, 2)->nullable();
            } $t->dateTime('ppa_fecha')->nullable();
            $t->dateTime('ppa_fecha_procesado')->nullable();
            $t->dateTime('ppa_a_fecha')->nullable();
        });
        Schema::create('stj_pedidos_detalle', function (Blueprint $t) {
            $t->bigInteger('car_id', true);
            foreach (['car_pais', 'car_sesion', 'car_usuario', 'car_producto', 'car_cantidad', 'car_promocion_id', 'car_total_facturado', 'car_a_version', 'car_origen', 'car_selected'] as $c) {
                $t->bigInteger($c)->nullable();
            } foreach (['car_tipo', 'car_accion', 'car_talla', 'car_ref', 'car_estilo_final', 'car_talla_final', 'car_modificar', 'car_a_usuario', 'car_a_ip', 'car_a_generales', 'car_promocion'] as $c) {
                $t->string($c)->nullable();
            } foreach (['car_precio', 'car_descuento', 'car_descuento_final'] as $c) {
                $t->decimal($c, 12, 2)->nullable();
            } $t->dateTime('car_fecha')->nullable();
            $t->dateTime('car_a_fecha')->nullable();
        });
    }
}
