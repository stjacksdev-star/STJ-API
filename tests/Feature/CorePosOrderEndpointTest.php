<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CorePosOrderEndpointTest extends TestCase
{
    private string $token = 'stj_corepos_test_token_with_enough_entropy';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_api_clientes', function (Blueprint $table) {
            $table->id('apc_id');
            $table->string('apc_token_hash', 64)->unique();
            $table->string('apc_estado');
            $table->unsignedBigInteger('apc_pais_id');
            $table->string('apc_permiso');
            $table->dateTime('apc_expira_en')->nullable();
            $table->dateTime('apc_ultimo_uso')->nullable();
        });
        Schema::create('stj_api_consultas', function (Blueprint $table) {
            $table->id('aqc_id');
            $table->uuid('aqc_uuid');
            $table->unsignedBigInteger('aqc_cliente_id')->nullable();
            $table->string('aqc_endpoint');
            $table->string('aqc_metodo');
            $table->string('aqc_referencia')->nullable();
            $table->unsignedBigInteger('aqc_pais_id')->nullable();
            $table->string('aqc_ip')->nullable();
            $table->string('aqc_user_agent', 500)->nullable();
            $table->unsignedSmallInteger('aqc_http_estado');
            $table->string('aqc_resultado');
            $table->unsignedInteger('aqc_duracion_ms')->nullable();
            $table->string('aqc_mensaje', 500)->nullable();
            $table->dateTime('aqc_creado_en');
        });
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->id('ped_id');
            $table->unsignedBigInteger('ped_id_pais');
            $table->string('ped_estatus');
            $table->string('ped_nombres');
            $table->string('ped_apellidos');
            $table->string('ped_email');
            $table->string('ped_tipo_identificacion');
            $table->string('ped_identificacion');
            $table->string('ped_pais');
            $table->string('ped_direccion');
            $table->string('ped_telefono_pais');
            $table->string('ped_telefono');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pedido');
            $table->string('ppa_estado');
            $table->string('ppa_ref');
            $table->dateTime('ppa_fecha');
            $table->decimal('ppa_monto_sdesc', 12, 4);
            $table->decimal('ppa_monto_senv', 12, 4);
            $table->decimal('ppa_monto', 12, 4);
            $table->string('ppa_tipo');
            $table->string('ppa_emisor')->nullable();
            $table->string('ppa_autorizacion')->nullable();
        });
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->id('pro_id');
            $table->string('pro_codigo');
        });
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->string('prm_nombre');
        });
        Schema::create('stj_pedidos_detalle', function (Blueprint $table) {
            $table->id('car_id');
            $table->unsignedBigInteger('car_producto');
            $table->string('car_ref');
            $table->string('car_talla');
            $table->integer('car_cantidad');
            $table->decimal('car_precio', 12, 4);
            $table->decimal('car_descuento', 8, 4);
            $table->unsignedBigInteger('car_promocion_id')->nullable();
            $table->string('car_promocion')->nullable();
        });

        DB::table('stj_api_clientes')->insert([
            'apc_id' => 1,
            'apc_token_hash' => hash('sha256', $this->token),
            'apc_estado' => 'ACTIVO',
            'apc_pais_id' => 1,
            'apc_permiso' => 'sv.billing.orders.read',
        ]);
        DB::table('stj_pedidos')->insert([
            ['ped_id' => 10, 'ped_id_pais' => 1, 'ped_estatus' => 'RECIBIDO', 'ped_nombres' => 'Ana', 'ped_apellidos' => 'López', 'ped_email' => 'ana@example.com', 'ped_tipo_identificacion' => 'DUI', 'ped_identificacion' => '00000000-0', 'ped_pais' => 'El Salvador', 'ped_direccion' => 'San Salvador', 'ped_telefono_pais' => '+503', 'ped_telefono' => '70001234'],
            ['ped_id' => 20, 'ped_id_pais' => 2, 'ped_estatus' => 'RECIBIDO', 'ped_nombres' => 'Otro', 'ped_apellidos' => 'País', 'ped_email' => 'otro@example.com', 'ped_tipo_identificacion' => 'DPI', 'ped_identificacion' => '1', 'ped_pais' => 'Guatemala', 'ped_direccion' => 'Guatemala', 'ped_telefono_pais' => '+502', 'ped_telefono' => '50000000'],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_id' => 100, 'ppa_pedido' => 10, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ-100', 'ppa_fecha' => '2026-09-14 10:30:00', 'ppa_monto_sdesc' => 100.1234, 'ppa_monto_senv' => 80.5678, 'ppa_monto' => 83.0678, 'ppa_tipo' => 'TARJETA', 'ppa_emisor' => 'VISA', 'ppa_autorizacion' => 'ABC123'],
            ['ppa_id' => 200, 'ppa_pedido' => 20, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ-200', 'ppa_fecha' => '2026-09-14 11:30:00', 'ppa_monto_sdesc' => 20, 'ppa_monto_senv' => 20, 'ppa_monto' => 20, 'ppa_tipo' => 'TARJETA', 'ppa_emisor' => 'VISA', 'ppa_autorizacion' => 'XYZ'],
        ]);
        DB::table('stj_productos')->insert(['pro_id' => 5, 'pro_codigo' => '3080186902']);
        DB::table('stj_promociones')->insert(['prm_id' => 7, 'prm_nombre' => 'NOMBRE PROMOCION']);
        DB::table('stj_pedidos_detalle')->insert([
            'car_id' => 50,
            'car_producto' => 5,
            'car_ref' => 'STJ-100',
            'car_talla' => '2T',
            'car_cantidad' => 2,
            'car_precio' => 12.9876,
            'car_descuento' => 20.1234,
            'car_promocion_id' => 7,
            'car_promocion' => 'PROMO PRUEBA',
        ]);
    }

    public function test_authorized_client_can_read_an_el_salvador_order_without_calculated_amounts(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/sv/billing/orders/STJ-100');

        $response->assertOk()
            ->assertJsonPath('data.pedido.stj', 'STJ-100')
            ->assertJsonPath('data.pedido.pais', 'SV')
            ->assertJsonPath('data.cliente.telefono', '+503 7000-1234')
            ->assertJsonPath('data.pago.autorizacion', 'ABC123')
            ->assertJsonPath('data.totales.monto_sin_descuento', 100.1234)
            ->assertJsonPath('data.totales.total_sin_envio', 80.5678)
            ->assertJsonPath('data.totales.total', 83.0678)
            ->assertJsonPath('data.items.0.sku', '3080186902-2T')
            ->assertJsonPath('data.items.0.precio', 12.9876)
            ->assertJsonPath('data.items.0.porcentaje_descuento', 20.1234)
            ->assertJsonPath('data.items.0.promocion', 'NOMBRE PROMOCION')
            ->assertJsonMissingPath('data.items.0.sub_total')
            ->assertJsonMissingPath('data.items.0.monto_descuento');

        $this->assertDatabaseHas('stj_api_consultas', [
            'aqc_cliente_id' => 1,
            'aqc_referencia' => 'STJ-100',
            'aqc_http_estado' => 200,
            'aqc_resultado' => 'EXITOSO',
        ]);
        $this->assertDatabaseMissing('stj_api_consultas', ['aqc_user_agent' => $this->token]);
    }

    public function test_it_uses_the_saved_promotion_as_a_fallback_for_historical_items(): void
    {
        DB::table('stj_pedidos_detalle')->where('car_id', 50)->update(['car_promocion_id' => null]);

        $this->withToken($this->token)
            ->getJson('/api/v1/sv/billing/orders/STJ-100')
            ->assertOk()
            ->assertJsonPath('data.items.0.promocion', 'PROMO PRUEBA');
    }

    public function test_it_rejects_missing_tokens_and_does_not_expose_other_countries(): void
    {
        $this->getJson('/api/v1/sv/billing/orders/STJ-100')->assertUnauthorized();
        $this->withToken($this->token)
            ->getJson('/api/v1/sv/billing/orders/STJ-200')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pedido no encontrado.');

        $this->assertDatabaseHas('stj_api_consultas', ['aqc_http_estado' => 401, 'aqc_resultado' => 'NO_AUTORIZADO']);
        $this->assertDatabaseHas('stj_api_consultas', ['aqc_http_estado' => 404, 'aqc_resultado' => 'NO_ENCONTRADO']);
    }
}
