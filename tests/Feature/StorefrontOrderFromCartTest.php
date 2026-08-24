<?php

namespace Tests\Feature;

use App\Models\StorefrontCart;
use App\Models\StorefrontVisitor;
use App\Services\Payments\PowerTranzClient;
use App\Services\Payments\PowerTranzConfigResolver;
use App\Services\Payments\PowerTranzPayloadFactory;
use App\Services\Payments\PowerTranzPaymentService;
use App\Services\StorefrontCheckoutValidationService;
use App\Services\StorefrontOrderService;
use App\Services\StorefrontPaymentEventService;
use App\Services\StorefrontProductPricingService;
use App\Services\StorefrontShippingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontOrderFromCartTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('fulfillmentCases')]
    public function test_order_uses_exact_authorized_store_code(string $countryCode, int $countryId, string $type, string $storeCode, string $paymentType = 'TARJETA', bool $allowed = true, bool $gatewayApproved = true): void
    {
        $this->schema();
        DB::table('stj_paises')->insert(['pai_id' => $countryId, 'pai_id_world' => $countryId, 'pai_codigo' => strtoupper($countryCode)]);
        DB::table('stj_world_countries')->insert(['id' => $countryId, 'iso2' => strtoupper($countryCode), 'name' => "Country {$countryCode}", 'phonecode' => '503']);
        DB::table('stj_world_states')->insert(['id' => 2, 'country_id' => $countryId, 'name' => 'Cortes', 'estado' => 1]);
        DB::table('stj_world_cities')->insert(['id' => 11, 'state_id' => 2, 'country_id' => $countryId, 'name' => 'SPS']);
        DB::table('stj_tiendas')->insert(['tie_id' => 8, 'tie_pais' => $countryId, 'tie_codigo' => $storeCode, 'tie_nombre' => 'Tienda autorizada']);
        DB::table('stj_productos')->insert(['pro_id' => 10, 'pro_codigo' => 'SKU10', 'pro_nombre' => 'Producto', 'pro_tallas' => 'S', 'pro_estatus' => 'ACTIVO']);
        DB::table('stj_producto_pais')->insert(['ppa_pais' => $countryId, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 25, 'ppa_precio_talla' => 'NO', 'ppa_descuento' => 0]);
        $hasPromotion = $type === 'TIENDA' && $paymentType === 'EFECTIVO';
        if ($hasPromotion) {
            DB::table('stj_promociones')->insert([
                'prm_id' => 2000, 'prm_pais' => $countryId, 'prm_nombre' => 'PROMO TEST',
                'prm_nombre_comercial' => 'Promoción central de prueba', 'prm_tipo' => 'PRODUCTOS',
                'prm_tipo_promocion' => 'DESCUENTO', 'prm_porcentaje' => 20,
                'prm_tipo_checkout' => 'T', 'prm_alcance_tienda' => 'SELECCIONADAS',
                'prm_aplica' => 'TODO', 'prm_estado' => 'EN-PROCESO',
                'prm_modalidad' => 'PROGRAMADO', 'prm_origen' => 'WEB',
            ]);
            DB::table('stj_promociones_horario')->insert([
                'pho_promocion' => 2000, 'pho_tipo' => 'NORMAL',
                'pho_inicio' => now()->subHour(), 'pho_fin' => now()->addHour(), 'pho_estado' => 'ACTIVO',
            ]);
            DB::table('stj_promociones_producto')->insert([
                'ppr_promocion' => 2000, 'ppr_producto' => 10, 'ppr_descuento' => null, 'ppr_precio' => null,
            ]);
            DB::table('stj_promociones_tienda')->insert(['prt_promocion' => 2000, 'prt_tienda' => 8]);
        }
        $visitor = StorefrontVisitor::query()->create(['vis_uuid' => (string) Str::uuid(), 'vis_origen' => 'WEB', 'vis_pais_id' => $countryId, 'vis_primera_visita' => now(), 'vis_ultima_visita' => now(), 'vis_expira_en' => now()->addYear(), 'vis_creado_en' => now(), 'vis_actualizado_en' => now()]);
        $cart = StorefrontCart::query()->create(['car_uuid' => (string) Str::uuid(), 'car_visitante_id' => $visitor->getKey(), 'car_pais_id' => $countryId, 'car_tipo' => $type, 'car_tienda_id' => 8, 'car_tienda_codigo_snapshot' => $storeCode, 'car_inventory_source' => 'external_api', 'car_estado' => 'CHECKOUT', 'car_origen' => 'WEB', 'car_moneda' => 'USD', 'car_version' => 2, 'car_ultima_actividad_en' => now(), 'car_expira_en' => now()->addMonth(), 'car_checkout_en' => now(), 'car_creado_en' => now(), 'car_actualizado_en' => now()]);
        StorefrontCart::query()->create(['car_uuid' => (string) Str::uuid(), 'car_visitante_id' => $visitor->getKey(), 'car_pais_id' => $countryId, 'car_tipo' => $type, 'car_tienda_id' => 8, 'car_tienda_codigo_snapshot' => $storeCode, 'car_inventory_source' => 'external_api', 'car_estado' => 'CONVERTIDO', 'car_origen' => 'WEB', 'car_moneda' => 'USD', 'car_version' => 3, 'car_ultima_actividad_en' => now()->subMinute(), 'car_expira_en' => now()->addMonth(), 'car_checkout_en' => now()->subMinutes(2), 'car_convertido_en' => now()->subMinute(), 'car_creado_en' => now()->subMinutes(3), 'car_actualizado_en' => now()->subMinute()]);
        DB::table('stj_carrito_detalles')->insert(['cad_carrito_id' => $cart->getKey(), 'cad_producto_id' => 10, 'cad_talla' => 'S', 'cad_ref' => 'SKU10', 'cad_cantidad' => 2, 'cad_precio_unitario' => 1, 'cad_descuento_unitario' => 0, 'cad_precio_final_unitario' => 1, 'cad_seleccionado' => 1, 'cad_estado' => 'DISPONIBLE', 'cad_creado_en' => now(), 'cad_actualizado_en' => now()]);
        config(["inventory.domicilio_store_by_country.{$countryCode}" => $storeCode]);
        $validator = Mockery::mock(StorefrontCheckoutValidationService::class);
        $validator->shouldReceive('validate')->times($allowed ? 2 : 0)->andReturn(['ok' => true]);
        $shipping = Mockery::mock(StorefrontShippingService::class);
        $shipping->shouldReceive('quote')->times($allowed ? 1 : 0)->andReturn(['shipping_amount' => '0.00', 'display_amount' => 'GRATIS', 'currency' => 'USD', 'currency_symbol' => '$', 'source' => $type === 'TIENDA' ? 'STORE_PICKUP' : 'FREE_RULE', 'rule_id' => null, 'minimum_free_shipping' => '0.00', 'remaining_for_free_shipping' => '0.00', 'message' => 'Sin costo', 'city' => $type === 'DOMICILIO' ? ['id' => 11, 'name' => 'SPS', 'stateId' => 2, 'state' => 'Cortes', 'urbanId' => null] : null]);
        $service = new StorefrontOrderService($validator, new StorefrontProductPricingService, $shipping);
        $payload = ['operation_uuid' => (string) Str::uuid(), 'customer' => ['firstName' => 'Ana', 'lastName' => 'Lopez', 'email' => 'ana@example.com', 'phone' => '9999', 'documentType' => 'DUI', 'document' => 'ID', 'countryId' => $countryId, 'stateId' => 2, 'cityId' => 11, 'address' => 'Residencia'], 'delivery' => ['city_id' => 11, 'state_id' => 2, 'city' => 'SPS', 'addressLine1' => 'Direccion'], 'payment_type' => $paymentType, 'items' => [['price' => 0.01]], 'guestCartId' => 'falso'];
        if ($hasPromotion) {
            $payload += ['_origin' => 'APP', '_platform' => 'IOS', '_app_version' => '2.2.34'];
        }
        $destination = $type === 'TIENDA'
            ? ['city_id' => 0, 'state_id' => 0, 'address' => '', 'reference' => '']
            : ['city_id' => 11, 'state_id' => 2, 'address' => 'direccion', 'reference' => ''];
        DB::table('stj_carrito_operaciones')->insert(['cao_uuid' => (string) Str::uuid(), 'cao_carrito_id' => $cart->getKey(), 'cao_visitante_id' => $visitor->getKey(), 'cao_tipo' => 'CHECKOUT_START', 'cao_payload_hash' => hash('sha256', 'checkout'), 'cao_respuesta' => json_encode(['checkout' => ['destinationHash' => hash('sha256', json_encode($destination, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]]), 'cao_creado_en' => now()]);

        if (! $allowed) {
            $this->expectException(ValidationException::class);
            $service->createFromCart($countryCode, $visitor, null, $payload);

            return;
        }

        $first = $service->createFromCart($countryCode, $visitor, null, $payload);
        $retry = $service->createFromCart($countryCode, $visitor, null, $payload);

        $this->assertSame($first, $retry);
        $this->assertDatabaseHas('stj_pedidos', ['ped_id' => $first['order']['pedidoId'], 'ped_tienda' => $storeCode]);
        $this->assertDatabaseHas('stj_pedidos', ['ped_id' => $first['order']['pedidoId'], 'ped_tipo_identificacion' => 'DUI', 'ped_identificacion' => 'ID', 'ped_departamento' => 2, 'ped_municipio' => 11, 'ped_direccion' => 'Residencia', 'ped_estatus' => $paymentType === 'EFECTIVO' ? 'RECIBIDO' : 'PENDIENTE_PAGO']);
        $relation = $type === 'DOMICILIO' ? 'stj_pedidos_direccion' : 'stj_pedidos_tienda';
        $foreign = $type === 'DOMICILIO' ? 'pdi_pedido' : 'pti_pedido';
        $this->assertDatabaseHas($relation, [$foreign => $first['order']['pedidoId']]);
        $this->assertDatabaseHas('stj_carritos', ['car_id' => $cart->getKey(), 'car_estado' => 'CONVERTIDO', 'car_pedido_id' => $first['order']['pedidoId']]);
        $this->assertDatabaseHas('stj_pedidos_detalle', ['car_precio' => 25, 'car_cantidad' => 2]);
        if ($hasPromotion) {
            $this->assertDatabaseHas('stj_pedidos', ['ped_id' => $first['order']['pedidoId'], 'ped_origen' => 'APP', 'ped_plataforma' => 'IOS', 'ped_vapp' => '2.2.34']);
            $this->assertSame('50.00', $first['order']['baseSubtotal']);
            $this->assertSame('10.00', $first['order']['discount']);
            $this->assertSame('40.00', $first['order']['total']);
            $this->assertDatabaseHas('stj_pedidos_detalle', ['car_promocion_id' => 2000, 'car_promocion' => 'Promoción central de prueba', 'car_descuento' => 20]);
            $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_monto_sdesc' => 50, 'ppa_monto_senv' => 40, 'ppa_monto' => 40]);
        }
        $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_id' => $first['order']['pagoId'], 'ppa_tipo' => $paymentType]);
        $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_id' => $first['order']['pagoId'], 'ppa_estado' => $paymentType === 'EFECTIVO' ? 'APROBADA' : 'PENDIENTE', 'ppa_autorizacion' => $paymentType === 'EFECTIVO' ? 'Efectivo' : null, 'ppa_pagado' => $paymentType === 'EFECTIVO' ? 'NO' : 'N/A']);
        $configuration = Mockery::mock(PowerTranzConfigResolver::class);
        $client = Mockery::mock(PowerTranzClient::class);
        $paymentEvents = new StorefrontPaymentEventService;
        $powerTranz = new PowerTranzPaymentService($configuration, new PowerTranzPayloadFactory, $client, $paymentEvents);
        config(['powertranz.return_base_url' => 'https://api.example.test/api/storefront/payments/powertranz/return']);
        if ($paymentType === 'TARJETA') {
            $operationUuid = (string) Str::uuid();
            $returnToken = null;
            $paymentReference = (string) DB::table('stj_pedidos_pago')->where('ppa_id', $first['order']['pagoId'])->value('ppa_ref');
            $configuration->shouldReceive('forCountry')->twice()->andReturn(['environment' => 'staging', 'sale_url' => 'https://staging.ptranz.com/api/spi/sale', 'payment_url' => 'https://staging.ptranz.com/api/spi/payment', 'id' => 'id', 'password' => 'password', 'currency' => $countryCode === 'hn' ? '340' : ($countryCode === 'gt' ? '320' : ($countryCode === 'cr' ? '188' : '840')), 'connect_timeout' => 2, 'timeout' => 5]);
            $client->shouldReceive('sale')->once()->andReturnUsing(function ($config, $payload) use (&$returnToken) {
                $returnToken = basename($payload['ExtendedData']['MerchantResponseUrl']);

                return ['RedirectData' => '<form method="post"></form>'];
            });
            $client->shouldReceive('confirm')->once()->andReturn(['Approved' => $gatewayApproved, 'TransactionIdentifier' => $operationUuid, 'OrderIdentifier' => $paymentReference, 'CurrencyCode' => $countryCode === 'hn' ? '340' : ($countryCode === 'gt' ? '320' : ($countryCode === 'cr' ? '188' : '840')), 'TotalAmount' => '50.00', 'AuthorizationCode' => $gatewayApproved ? 'AUTH' : null, 'IsoResponseCode' => $gatewayApproved ? '00' : '05', 'ResponseMessage' => $gatewayApproved ? 'Approved' : 'Declined']);
            $started = $powerTranz->start((int) $first['order']['pedidoId'], $visitor, null, ['operation_uuid' => $operationUuid, 'card' => ['pan' => str_repeat('4', 16), 'cvv' => '123', 'expiration' => '3012', 'holder' => 'ANA LOPEZ']]);
            $this->assertSame('PENDIENTE', $started['status']);
            $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_id' => $first['order']['pagoId'], 'ppa_emisor' => 'VISA']);
            $this->assertSame(1, DB::table('stj_powertranz_operaciones')->count());
            $this->assertStringNotContainsString(str_repeat('4', 16), (string) DB::table('stj_powertranz_operaciones')->value('pto_respuesta_segura'));
            $this->assertStringNotContainsString('123', (string) DB::table('stj_powertranz_operaciones')->value('pto_respuesta_segura'));
            $confirmed = $powerTranz->confirm($countryCode, $returnToken, ['SpiToken' => 'opaque', 'TransactionIdentifier' => $operationUuid, 'Response' => 'browser-value-is-not-authority']);
            $repeated = $powerTranz->confirm($countryCode, $returnToken, ['SpiToken' => 'opaque', 'TransactionIdentifier' => $operationUuid, 'Response' => 'different-browser-value']);
            $this->assertSame($gatewayApproved ? 'APROBADA' : 'DENEGADA', $confirmed['status']);
            $this->assertSame($confirmed, $repeated);
            $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_id' => $first['order']['pagoId'], 'ppa_estado' => $gatewayApproved ? 'APROBADA' : 'DENEGADA', 'ppa_autorizacion' => $gatewayApproved ? 'AUTH' : null, 'ppa_rsp_codigo' => $gatewayApproved ? '00' : '05', 'ppa_rsp_mensaje' => $gatewayApproved ? 'Approved' : 'Declined']);
            $this->assertDatabaseHas('stj_pedidos', ['ped_id' => $first['order']['pedidoId'], 'ped_estatus' => $gatewayApproved ? 'RECIBIDO' : 'PENDIENTE_PAGO']);
            $this->assertSame($gatewayApproved ? 1 : 0, DB::table('stj_cliente_eventos')->where('cev_tipo', 'PURCHASE')->count());
            if (! $gatewayApproved) {
                $this->assertDatabaseHas('stj_carritos', [
                    'car_id' => $cart->getKey(),
                    'car_estado' => 'ACTIVO',
                    'car_pedido_id' => $first['order']['pedidoId'],
                ]);
            }
        } else {
            try {
                $powerTranz->start((int) $first['order']['pedidoId'], $visitor, null, ['operation_uuid' => (string) Str::uuid(), 'card' => ['pan' => str_repeat('4', 16), 'cvv' => '123', 'expiration' => '3012', 'holder' => 'ANA LOPEZ']]);
                $this->fail('Un pago EFECTIVO no debe iniciar PowerTranz.');
            } catch (ValidationException) {
                $this->assertSame(0, DB::table('stj_powertranz_operaciones')->count());
            }
        }
        $this->assertSame(1, DB::table('stj_cliente_eventos')->where('cev_tipo', 'ORDER_CREATED')->count());
        $payments = new StorefrontPaymentEventService;
        $payments->record((int) $first['order']['pagoId'], 'DENEGADA', (string) Str::uuid());
        $this->assertSame($paymentType === 'TARJETA' && $gatewayApproved ? 1 : 0, DB::table('stj_cliente_eventos')->where('cev_tipo', 'PURCHASE')->count());
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
            'tienda con efectivo' => ['sv', 1, 'TIENDA', '001', 'EFECTIVO'],
            'tienda con tarjeta' => ['sv', 1, 'TIENDA', '001', 'TARJETA'],
            'domicilio rechaza efectivo' => ['sv', 1, 'DOMICILIO', '57', 'EFECTIVO', false],
            'tarjeta rechazada no genera purchase' => ['sv', 1, 'TIENDA', '001', 'TARJETA', true, false],
        ];
    }

    private function schema(): void
    {
        Schema::create('stj_world_countries', function (Blueprint $t) {
            $t->bigInteger('id');
            $t->string('iso2', 3);
            $t->string('name');
            $t->string('phonecode');
        });
        Schema::create('stj_world_states', function (Blueprint $t) {
            $t->bigInteger('id');
            $t->bigInteger('country_id');
            $t->string('name');
            $t->boolean('estado')->default(true);
        });
        Schema::create('stj_world_cities', function (Blueprint $t) {
            $t->bigInteger('id');
            $t->bigInteger('state_id');
            $t->bigInteger('country_id');
            $t->string('name');
        });
        Schema::create('stj_paises', fn (Blueprint $t) => tap($t->bigInteger('pai_id', true), function () use ($t) {
            $t->bigInteger('pai_id_world')->nullable();
            $t->string('pai_codigo', 3);
        }));
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
        Schema::create('stj_promociones', function (Blueprint $t) {
            $t->bigInteger('prm_id', true);
            $t->bigInteger('prm_pais');
            foreach (['prm_nombre', 'prm_nombre_comercial', 'prm_tipo', 'prm_tipo_promocion', 'prm_restriccion', 'prm_tipo_checkout', 'prm_alcance_tienda', 'prm_aplica', 'prm_estado', 'prm_modalidad', 'prm_origen'] as $c) {
                $t->string($c)->nullable();
            }
            $t->decimal('prm_porcentaje', 12, 2)->nullable();
            $t->decimal('prm_precio', 12, 2)->nullable();
        });
        Schema::create('stj_promociones_horario', function (Blueprint $t) {
            $t->bigInteger('pho_promocion');
            $t->string('pho_tipo');
            $t->dateTime('pho_inicio');
            $t->dateTime('pho_fin');
            $t->string('pho_estado');
        });
        Schema::create('stj_promociones_producto', function (Blueprint $t) {
            $t->bigInteger('ppr_promocion');
            $t->bigInteger('ppr_producto');
            $t->decimal('ppr_descuento', 12, 2)->nullable();
            $t->decimal('ppr_precio', 12, 2)->nullable();
        });
        Schema::create('stj_promociones_tienda', function (Blueprint $t) {
            $t->bigInteger('prt_promocion');
            $t->bigInteger('prt_tienda');
        });
        Schema::create('stj_pedidos', function (Blueprint $t) {
            $t->bigInteger('ped_id', true);
            foreach (['ped_id_pais', 'ped_user', 'ped_a_version'] as $c) {
                $t->bigInteger($c)->nullable();
            } foreach (['ped_origen', 'ped_plataforma', 'ped_estatus', 'ped_estatus_productos', 'ped_checkout', 'ped_tienda', 'ped_login', 'ped_sesion', 'ped_nombres', 'ped_apellidos', 'ped_email', 'ped_tipo_identificacion', 'ped_identificacion', 'ped_rtu', 'ped_pais', 'ped_departamento', 'ped_municipio', 'ped_estado', 'ped_ciudad', 'ped_direccion', 'ped_telefono_pais', 'ped_telefono', 'ped_whatsapp_pais', 'ped_whatsapp', 'ped_devolucion_realizada', 'ped_rsp_servicio', 'ped_monto_devolucion', 'ped_correo_enviado', 'ped_a_usuario', 'ped_a_ip', 'ped_a_generales', 'ped_credito_fiscal', 'ped_vapp', 'ped_suscrito_mailing'] as $c) {
                $t->string($c)->nullable();
            } $t->dateTime('ped_fecha')->nullable();
            $t->dateTime('ped_a_fecha')->nullable();
        });
        Schema::create('stj_direcciones', function (Blueprint $t) {
            $t->bigInteger('dir_id', true);
            foreach (['dir_tipo', 'dir_misma_persona', 'dir_misma_direccion', 'dir_usuario', 'dir_pais', 'dir_direccion', 'dir_referencia', 'dir_departamento', 'dir_municipio', 'dir_departamento_txt', 'dir_municipio_txt', 'dir_persona', 'dir_telefono', 'dir_save', 'dir_a_usuario', 'dir_a_ip'] as $c) {
                $t->string($c)->nullable();
            } $t->dateTime('dir_fecha')->nullable();
            $t->dateTime('dir_a_fecha')->nullable();
            $t->integer('dir_a_version')->nullable();
        });
        Schema::create('stj_pedidos_direccion', function (Blueprint $t) {
            $t->bigInteger('pdi_id', true);
            foreach (['pdi_pedido', 'pdi_direccion', 'pdi_a_version'] as $c) {
                $t->bigInteger($c)->nullable();
            } foreach (['pdi_tipo_envio', 'pdi_id_urbano', 'pdi_id_shipping', 'pdi_costo_envio', 'pdi_costo_envio_txt', 'pdi_costo_envio_final', 'pdi_aplica_envio_gratis', 'pdi_moneda_envio', 'pdi_mensaje_envio', 'pdi_a_usuario', 'pdi_a_ip'] as $c) {
                $t->string($c)->nullable();
            } $t->decimal('pdi_monto_minimo_envio', 10, 2)->nullable();
            $t->decimal('pdi_falta_envio_gratis', 10, 2)->nullable();
            $t->dateTime('pdi_a_fecha')->nullable();
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
            } foreach (['ppa_tipo', 'ppa_estado', 'ppa_ref', 'ppa_emisor', 'ppa_autorizacion', 'ppa_transactionidentifier', 'ppa_rsp_codigo', 'ppa_rsp_mensaje', 'ppa_tarjeta', 'ppa_pagado', 'ppa_a_usuario', 'ppa_a_ip'] as $c) {
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
