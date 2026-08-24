<?php

namespace Tests\Feature;

use App\Exceptions\CartOperationConflict;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\Inventory\InventorySourceResolver;
use App\Services\ProductDetailAvailabilityService;
use App\Services\StorefrontCartService;
use App\Services\StorefrontCheckoutValidationService;
use App\Services\StorefrontFulfillmentService;
use App\Services\StorefrontProductPricingService;
use App\Services\StorefrontPromotionResolver;
use App\Services\StorefrontShippingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class StorefrontPersistentCartTest extends TestCase
{
    use RefreshDatabase;

    private StorefrontVisitor $visitor;

    private StorefrontCartService $service;

    private $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_paises', fn (Blueprint $t) => tap($t->bigInteger('pai_id', true), function () use ($t) {
            $t->bigInteger('pai_id_world')->nullable();
            $t->string('pai_codigo', 3);
        }));
        Schema::create('stj_productos', function (Blueprint $t) {
            $t->bigInteger('pro_id', true);
            $t->string('pro_codigo');
            $t->string('pro_nombre');
            $t->string('pro_tallas');
            $t->string('pro_estatus');
        });
        Schema::create('stj_producto_pais', function (Blueprint $t) {
            $t->bigInteger('ppa_id', true);
            $t->bigInteger('ppa_pais');
            $t->bigInteger('ppa_producto');
            $t->string('ppa_estado');
            $t->decimal('ppa_precio', 12, 2);
            $t->string('ppa_precio_talla')->nullable();
            $t->decimal('ppa_descuento', 5, 2)->nullable();
            $t->string('ppa_origen_descuento')->nullable();
            $t->string('ppa_promo_nombre')->nullable();
        });
        Schema::create('stj_producto_talla', function (Blueprint $t) {
            $t->bigInteger('pta_id', true);
            $t->bigInteger('pta_pais');
            $t->bigInteger('pta_producto');
            $t->string('pta_talla', 10);
            $t->decimal('pta_precio', 14, 5);
        });
        Schema::create('stj_usuarios', fn (Blueprint $t) => $t->bigInteger('usu_id', true));
        Schema::create('stj_tiendas', function (Blueprint $t) {
            $t->bigInteger('tie_id', true);
            $t->string('tie_codigo', 15);
            $t->string('tie_nombre');
            $t->bigInteger('tie_pais');
            $t->boolean('tie_productos');
        });
        Schema::create('stj_pedidos', fn (Blueprint $t) => $t->bigInteger('ped_id', true));
        Schema::create('stj_promociones', function (Blueprint $t) {
            $t->bigInteger('prm_id', true);
            $t->bigInteger('prm_pais');
            $t->string('prm_nombre');
            $t->string('prm_nombre_comercial')->nullable();
            $t->string('prm_tipo');
            $t->string('prm_tipo_promocion');
            $t->string('prm_restriccion')->nullable();
            $t->decimal('prm_porcentaje', 5, 2)->nullable();
            $t->decimal('prm_precio', 12, 2)->nullable();
            $t->string('prm_tipo_checkout')->nullable();
            $t->string('prm_alcance_tienda')->nullable();
            $t->string('prm_aplica')->nullable();
            $t->string('prm_estado');
            $t->string('prm_modalidad');
            $t->string('prm_origen');
        });
        Schema::create('stj_promociones_horario', function (Blueprint $t) {
            $t->bigInteger('pho_id', true);
            $t->bigInteger('pho_promocion');
            $t->string('pho_tipo');
            $t->dateTime('pho_inicio');
            $t->dateTime('pho_fin');
            $t->string('pho_estado');
        });
        Schema::create('stj_promociones_producto', function (Blueprint $t) {
            $t->bigInteger('ppr_promocion');
            $t->bigInteger('ppr_producto');
            $t->decimal('ppr_descuento', 5, 2)->nullable();
            $t->decimal('ppr_precio', 12, 2)->nullable();
        });
        Schema::create('stj_promociones_tienda', function (Blueprint $t) {
            $t->bigInteger('prt_promocion');
            $t->bigInteger('prt_tienda');
        });
        DB::table('stj_paises')->insert([['pai_id' => 1, 'pai_id_world' => 1, 'pai_codigo' => 'SV'], ['pai_id' => 2, 'pai_id_world' => 2, 'pai_codigo' => 'GT']]);
        DB::table('stj_productos')->insert(['pro_id' => 10, 'pro_codigo' => 'SKU10', 'pro_nombre' => 'Producto', 'pro_tallas' => 'S,M', 'pro_estatus' => 'ACTIVO']);
        DB::table('stj_producto_pais')->insert([['ppa_pais' => 1, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 100, 'ppa_precio_talla' => 'NO', 'ppa_descuento' => 10, 'ppa_origen_descuento' => 'WEB', 'ppa_promo_nombre' => 'Promo'], ['ppa_pais' => 2, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 200, 'ppa_precio_talla' => 'NO', 'ppa_descuento' => null, 'ppa_origen_descuento' => null, 'ppa_promo_nombre' => null]]);
        DB::table('stj_tiendas')->insert([['tie_id' => 1, 'tie_codigo' => '57', 'tie_nombre' => 'Domicilio SV', 'tie_pais' => 1, 'tie_productos' => 0], ['tie_id' => 2, 'tie_codigo' => '002', 'tie_nombre' => 'Las Cascadas', 'tie_pais' => 1, 'tie_productos' => 1], ['tie_id' => 3, 'tie_codigo' => '2', 'tie_nombre' => 'Domicilio GT', 'tie_pais' => 2, 'tie_productos' => 0]]);
        config(['inventory.domicilio_store_by_country.sv' => '57', 'inventory.domicilio_store_by_country.gt' => '2']);
        $this->visitor = StorefrontVisitor::query()->create(['vis_uuid' => (string) Str::uuid(), 'vis_origen' => 'WEB', 'vis_pais_id' => 1, 'vis_primera_visita' => now(), 'vis_ultima_visita' => now(), 'vis_expira_en' => now()->addYear(), 'vis_creado_en' => now(), 'vis_actualizado_en' => now()]);
        $availability = Mockery::mock(ProductDetailAvailabilityService::class);
        $availability->shouldReceive('forCountryAndSlug')->andReturnUsing(fn () => ['sizes' => [['size' => 'S', 'quantityInActiveStore' => 5], ['size' => 'M', 'quantityInActiveStore' => 2]]]);
        $checkout = Mockery::mock(StorefrontCheckoutValidationService::class);
        $checkout->shouldReceive('validate')->byDefault()->andReturn(['ok' => true, 'message' => 'ok']);
        $this->checkout = $checkout;
        $shipping = Mockery::mock(StorefrontShippingService::class);
        $shipping->shouldReceive('quote')->andReturnUsing(fn ($country, $type) => ['shipping_amount' => '0.00', 'display_amount' => 'GRATIS', 'currency' => $country->pai_codigo === 'GT' ? 'GTQ' : 'USD', 'currency_symbol' => '$', 'source' => $type === 'TIENDA' ? 'STORE_PICKUP' : 'FREE_RULE', 'rule_id' => null, 'minimum_free_shipping' => '0.00', 'remaining_for_free_shipping' => '0.00', 'message' => 'Sin costo', 'city' => null]);
        $this->service = new StorefrontCartService($availability, new StorefrontProductPricingService, new StorefrontFulfillmentService(new InventorySourceResolver), $checkout, $shipping, app(StorefrontPromotionResolver::class));
    }

    public function test_guest_add_is_authoritative_and_idempotent(): void
    {
        $input = ['operation_uuid' => (string) Str::uuid(), 'product_id' => 10, 'sku' => 'SKU10', 'size' => 'S', 'quantity' => 1, 'price' => 0.01, 'usu_id' => 999];
        $first = $this->service->add('sv', $this->visitor, null, $input);
        $retry = $this->service->add('sv', $this->visitor, null, $input);
        $this->assertSame($first, $retry);
        $this->assertDatabaseCount('stj_carrito_detalles', 1);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_cantidad' => 1, 'cad_precio_unitario' => 100, 'cad_descuento_unitario' => 10, 'cad_precio_final_unitario' => 90]);
        $this->assertDatabaseHas('stj_cliente_eventos', ['cev_tipo' => 'ADD_TO_CART', 'cev_usu_id' => null, 'cev_producto_id' => 10]);
        $this->expectException(CartOperationConflict::class);
        $this->service->add('sv', $this->visitor, null, array_merge($input, ['quantity' => 2]));
    }

    public function test_cart_ignores_global_product_country_discount_without_an_eligible_promotion(): void
    {
        $result = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));

        $this->assertEquals(100.0, $result['cart']['items'][0]['price']);
        $this->assertNull($result['cart']['items'][0]['promotion']);
        $this->assertEquals(0.0, $result['cart']['totals']['discount']);
        $this->assertEquals(100.0, $result['cart']['totals']['total']);
    }

    public function test_cart_applies_product_percentage_from_the_central_resolver(): void
    {
        $this->promotion(100, 'DESCUENTO-SKU');
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => 100,
            'ppr_producto' => 10,
            'ppr_descuento' => 25,
        ]);

        $result = $this->service->add('sv', $this->visitor, null, $this->item('S', 2));

        $this->assertEquals(75.0, $result['cart']['items'][0]['price']);
        $this->assertEquals(50.0, $result['cart']['items'][0]['promotionDiscount']);
        $this->assertSame(100, $result['cart']['items'][0]['promotion']['id']);
        $this->assertSame('25% de descuento', $result['cart']['items'][0]['promotion']['benefitLabel']);
        $this->assertEquals(200.0, $result['cart']['totals']['baseSubtotal']);
        $this->assertEquals(50.0, $result['cart']['totals']['discount']);
        $this->assertEquals(150.0, $result['cart']['totals']['total']);
    }

    public function test_cart_evaluates_two_for_one_using_the_complete_quantity(): void
    {
        $this->promotion(101, 'CONDICION-SKU', ['prm_restriccion' => '2x1']);
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => 101,
            'ppr_producto' => 10,
        ]);

        $one = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $two = $this->service->update('sv', $one['cart']['items'][0]['id'], $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(),
            'quantity' => 2,
        ]);

        $this->assertEquals(0.0, $one['cart']['totals']['discount']);
        $this->assertEquals(100.0, $two['cart']['totals']['discount']);
        $this->assertEquals(100.0, $two['cart']['totals']['total']);
        $this->assertSame('Aplica 2x1', $two['cart']['items'][0]['promotion']['benefitLabel']);
    }

    public function test_cart_recalculates_selected_store_promotion_after_fulfillment_change(): void
    {
        $this->promotion(102, 'DESCUENTO-SKU', [
            'prm_tipo_checkout' => 'T',
            'prm_alcance_tienda' => 'SELECCIONADAS',
        ]);
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => 102,
            'ppr_producto' => 10,
            'ppr_descuento' => 30,
        ]);
        DB::table('stj_promociones_tienda')->insert([
            'prt_promocion' => 102,
            'prt_tienda' => 2,
        ]);

        $home = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $store = $this->service->applyFulfillment('sv', $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(),
            'fulfillment_type' => 'TIENDA',
            'store_code' => '002',
            'confirm_affected' => true,
        ]);

        $this->assertEquals(0.0, $home['cart']['totals']['discount']);
        $this->assertEquals(30.0, $store['cart']['totals']['discount']);
        $this->assertEquals(70.0, $store['cart']['totals']['total']);
        $this->assertSame(102, $store['cart']['items'][0]['promotion']['id']);
        $this->assertSame(2, $store['cart']['items'][0]['promotion']['store']['id']);
    }

    public function test_same_variant_updates_and_other_size_creates_line(): void
    {
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $this->service->add('sv', $this->visitor, null, $this->item('S', 2));
        $this->service->add('sv', $this->visitor, null, $this->item('M', 1));
        $this->assertDatabaseCount('stj_carrito_detalles', 2);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_talla' => 'S', 'cad_cantidad' => 3]);
    }

    public function test_add_from_recommendation_is_derived_from_successful_cart_add(): void
    {
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1) + ['recommendation_placement' => 'PDP_RELATED', 'recommendation_reason' => 'SAME_CATEGORY', 'recommendation_position' => 2]);
        $this->assertDatabaseHas('stj_cliente_eventos', ['cev_tipo' => 'ADD_TO_CART', 'cev_producto_id' => 10]);
        $this->assertDatabaseHas('stj_cliente_eventos', ['cev_tipo' => 'ADD_FROM_RECOMMENDATION', 'cev_producto_id' => 10]);
    }

    public function test_remove_writes_audit_and_event(): void
    {
        $result = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $id = $result['cart']['items'][0]['id'];
        $this->service->remove('sv', $id, $this->visitor, null, ['operation_uuid' => (string) Str::uuid()]);
        $this->assertDatabaseCount('stj_carrito_detalles', 0);
        $this->assertDatabaseHas('stj_carrito_auditoria', ['cau_accion' => 'ITEM_REMOVED']);
        $this->assertDatabaseHas('stj_cliente_eventos', ['cev_tipo' => 'REMOVE_FROM_CART', 'cev_producto_id' => 10]);
    }

    public function test_customer_cart_is_private_and_countries_are_separate(): void
    {
        DB::table('stj_usuarios')->insert([['usu_id' => 7], ['usu_id' => 8]]);
        $a = StorefrontCustomer::query()->find(7);
        $b = StorefrontCustomer::query()->find(8);
        $guest = $this->service->get('sv', $this->visitor, null);
        $cartA = $this->service->get('sv', $this->visitor, $a);
        $cartB = $this->service->get('sv', $this->visitor, $b);
        $cartGt = $this->service->get('gt', $this->visitor, $a);
        $this->assertCount(4, array_unique([$guest['cart']['id'], $cartA['cart']['id'], $cartB['cart']['id'], $cartGt['cart']['id']]));
        $this->assertSame($guest['cart']['id'], $this->service->get('sv', $this->visitor, null)['cart']['id']);
    }

    public function test_merge_combines_same_variant_and_disables_guest_cart(): void
    {
        DB::table('stj_usuarios')->insert(['usu_id' => 7]);
        $customer = StorefrontCustomer::query()->find(7);
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $this->service->add('sv', $this->visitor, $customer, $this->item('S', 2));
        $merged = $this->service->merge('sv', $this->visitor, $customer, ['operation_uuid' => (string) Str::uuid()]);
        $this->assertSame(3, $merged['cart']['items'][0]['quantity']);
        $this->assertDatabaseHas('stj_carritos', ['car_usu_id' => null, 'car_estado' => 'MERGED']);
        $this->assertDatabaseHas('stj_carrito_auditoria', ['cau_accion' => 'CART_MERGED']);
    }

    public function test_inactive_product_is_rejected(): void
    {
        DB::table('stj_productos')->where('pro_id', 10)->update(['pro_estatus' => 'INACTIVO']);
        $this->expectException(ValidationException::class);
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
    }

    public function test_stock_caps_requested_quantity_with_alert(): void
    {
        $result = $this->service->add('sv', $this->visitor, null, $this->item('S', 9));
        $this->assertSame(5, $result['cart']['items'][0]['quantity']);
        $this->assertSame('INVENTORY_ADJUSTED', $result['cart']['alerts'][0]['type']);
    }

    public function test_item_from_another_customer_cannot_be_modified(): void
    {
        DB::table('stj_usuarios')->insert([['usu_id' => 7], ['usu_id' => 8]]);
        $a = StorefrontCustomer::query()->find(7);
        $b = StorefrontCustomer::query()->find(8);
        $result = $this->service->add('sv', $this->visitor, $a, $this->item('S', 1));
        $this->expectException(ValidationException::class);
        $this->service->update('sv', $result['cart']['items'][0]['id'], $this->visitor, $b, ['operation_uuid' => (string) Str::uuid(), 'quantity' => 2]);
    }

    public function test_size_prices_are_authoritative_and_country_scoped(): void
    {
        DB::table('stj_producto_pais')->where(['ppa_pais' => 1, 'ppa_producto' => 10])->update(['ppa_precio_talla' => 'SI']);
        DB::table('stj_producto_talla')->insert([
            ['pta_pais' => 1, 'pta_producto' => 10, 'pta_talla' => 'S', 'pta_precio' => '21.95025'],
            ['pta_pais' => 1, 'pta_producto' => 10, 'pta_talla' => 'M', 'pta_precio' => '23.95035'],
            ['pta_pais' => 2, 'pta_producto' => 10, 'pta_talla' => 'S', 'pta_precio' => '99.99000'],
        ]);
        $s = $this->service->add('sv', $this->visitor, null, $this->item('S', 1) + ['price' => '0.01']);
        $m = $this->service->add('sv', $this->visitor, null, $this->item('M', 1));
        $this->assertSame(21.95, $s['cart']['items'][0]['price']);
        $this->assertSame(23.95, collect($m['cart']['items'])->firstWhere('size', 'M')['price']);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_talla' => 'S', 'cad_precio_final_unitario' => '21.95']);
    }

    public function test_missing_size_price_is_rejected(): void
    {
        DB::table('stj_producto_pais')->where(['ppa_pais' => 1, 'ppa_producto' => 10])->update(['ppa_precio_talla' => 'SI']);
        $this->expectException(ValidationException::class);
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
    }

    public function test_get_reprices_once_and_audits_once(): void
    {
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        DB::table('stj_producto_pais')->where(['ppa_pais' => 1, 'ppa_producto' => 10])->update(['ppa_precio' => 110]);
        $first = $this->service->get('sv', $this->visitor, null);
        $second = $this->service->get('sv', $this->visitor, null);
        $this->assertSame('PRICE_CHANGED', $first['cart']['alerts'][0]['type']);
        $this->assertSame([], $second['cart']['alerts']);
        $this->assertSame(1, DB::table('stj_carrito_auditoria')->where('cau_accion', 'PRICE_CHANGED')->count());
    }

    public function test_fulfillment_preview_does_not_modify_and_apply_persists_idempotently(): void
    {
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $preview = $this->service->previewFulfillment('sv', $this->visitor, null, ['fulfillment_type' => 'TIENDA', 'store_code' => '002']);
        $this->assertSame('DOMICILIO', $preview['current']['type']);
        $this->assertSame('TIENDA', $preview['proposed']['type']);
        $this->assertSame(1, $preview['impact']['items'][0]['requestedQuantity']);
        $this->assertSame(5, $preview['impact']['items'][0]['availableQuantity']);
        $this->assertTrue($preview['impact']['items'][0]['availability']);
        $this->assertArrayHasKey('priceChanged', $preview['impact']['items'][0]);
        $this->assertArrayHasKey('promotionChanged', $preview['impact']['items'][0]);
        $this->assertDatabaseHas('stj_carritos', ['car_tipo' => 'DOMICILIO', 'car_tienda_codigo_snapshot' => '57']);

        $input = ['operation_uuid' => (string) Str::uuid(), 'fulfillment_type' => 'TIENDA', 'store_code' => '002', 'confirm_affected' => true];
        $first = $this->service->applyFulfillment('sv', $this->visitor, null, $input);
        $retry = $this->service->applyFulfillment('sv', $this->visitor, null, $input);
        $this->assertSame($first, $retry);
        $this->assertSame('002', $first['cart']['fulfillment']['storeCode']);
        $this->assertDatabaseHas('stj_carrito_auditoria', ['cau_accion' => 'FULFILLMENT_CHANGED']);
    }

    public function test_store_selection_is_country_scoped_and_codes_remain_text(): void
    {
        DB::table('stj_tiendas')->insert([
            ['tie_id' => 4, 'tie_codigo' => '001', 'tie_nombre' => 'Sucursal SV 001', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_id' => 5, 'tie_codigo' => '001', 'tie_nombre' => 'Sucursal GT 001', 'tie_pais' => 2, 'tie_productos' => 1],
            ['tie_id' => 6, 'tie_codigo' => '1', 'tie_nombre' => 'Sucursal SV 1', 'tie_pais' => 1, 'tie_productos' => 1],
        ]);
        $leadingZeros = $this->service->previewFulfillment('sv', $this->visitor, null, ['fulfillment_type' => 'TIENDA', 'store_code' => '001']);
        $one = $this->service->previewFulfillment('sv', $this->visitor, null, ['fulfillment_type' => 'TIENDA', 'store_code' => '1']);
        $this->assertSame(4, $leadingZeros['proposed']['storeId']);
        $this->assertSame('001', $leadingZeros['proposed']['storeCode']);
        $this->assertSame(6, $one['proposed']['storeId']);
    }

    public function test_checkout_start_is_authoritative_idempotent_and_mutation_invalidates_it(): void
    {
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $operation = ['operation_uuid' => (string) Str::uuid()];
        $first = $this->service->startCheckout('sv', $this->visitor, null, $operation);
        $retry = $this->service->startCheckout('sv', $this->visitor, null, $operation);
        $this->assertSame($first, $retry);
        $this->assertSame('CHECKOUT', $first['cart']['state']);
        $this->assertSame('57', $first['checkout']['operationalStoreCode']);
        $this->assertSame(1, DB::table('stj_cliente_eventos')->where('cev_tipo', 'BEGIN_CHECKOUT')->count());

        $this->service->add('sv', $this->visitor, null, $this->item('M', 1));
        $this->assertDatabaseHas('stj_carritos', ['car_estado' => 'ACTIVO', 'car_checkout_en' => null]);
        $this->assertDatabaseHas('stj_carrito_auditoria', ['cau_accion' => 'CHECKOUT_INVALIDATED']);
    }

    public function test_checkout_does_not_silently_omit_an_unavailable_unselected_line(): void
    {
        $result = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        DB::table('stj_carrito_detalles')->where('cad_carrito_id', $result['cart']['id'])->update([
            'cad_estado' => 'SIN_EXISTENCIA',
            'cad_seleccionado' => 0,
        ]);
        $this->checkout->shouldReceive('validate')->once()
            ->withArgs(fn ($country, $fulfillment, $items) => $country === 'sv'
                && count($items) === 1
                && $items[0]['sku'] === 'SKU10'
                && $items[0]['size'] === 'S')
            ->andReturn([
                'ok' => false,
                'message' => 'Hay productos sin stock suficiente para el metodo de entrega elegido.',
                'lines' => [[
                    'sku' => 'SKU10', 'name' => 'SKU10', 'size' => 'S',
                    'requestedQuantity' => 1, 'availableQuantity' => 0, 'ok' => false,
                ]],
            ]);

        try {
            $this->service->startCheckout('sv', $this->visitor, null, ['operation_uuid' => (string) Str::uuid()]);
            $this->fail('Checkout debio bloquear la linea sin existencia.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->assertStringContainsString('SKU10, talla S', $message);
            $this->assertStringContainsString('solicitadas 1, disponibles 0', $message);
        }

        $this->assertDatabaseHas('stj_carritos', ['car_id' => $result['cart']['id'], 'car_estado' => 'ACTIVO']);
    }

    public function test_cart_checkout_scope_validation_keeps_unavailable_line_visible_and_blocks_it(): void
    {
        $result = $this->service->add('sv', $this->visitor, null, $this->item('M', 2));
        $itemId = $result['cart']['items'][0]['id'];
        $this->checkout->shouldReceive('validate')->once()->andReturn([
            'ok' => false,
            'message' => 'Hay productos sin stock suficiente para el metodo de entrega elegido.',
            'inventorySource' => ['configuredSource' => 'external_api', 'usedSource' => 'external_api'],
            'lines' => [[
                'key' => (string) $itemId, 'sku' => 'SKU10', 'name' => 'SKU10', 'size' => 'M',
                'requestedQuantity' => 2, 'availableQuantity' => 1, 'ok' => false,
                'message' => 'Solo hay 1 unidad(es) disponibles.',
            ]],
        ]);

        $validation = $this->service->validateForCheckout('sv', $this->visitor, null);

        $this->assertFalse($validation['ok']);
        $this->assertCount(1, $validation['cart']['items']);
        $this->assertSame('SIN_EXISTENCIA', $validation['cart']['items'][0]['status']);
        $this->assertTrue($validation['cart']['items'][0]['selected']);
        $this->assertSame('Solo hay 1 unidad(es) disponibles.', $validation['cart']['items'][0]['unavailableReason']);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_id' => $itemId, 'cad_estado' => 'SIN_EXISTENCIA', 'cad_seleccionado' => 1]);
    }

    public function test_mobile_checkout_validation_only_checks_selected_lines(): void
    {
        $first = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $second = $this->service->add('sv', $this->visitor, null, $this->item('M', 1));
        $firstId = $first['cart']['items'][0]['id'];
        $secondId = collect($second['cart']['items'])->firstWhere('size', 'M')['id'];
        DB::table('stj_carrito_detalles')->where('cad_id', $firstId)->update(['cad_seleccionado' => 0]);
        $this->checkout->shouldReceive('validate')->once()->withArgs(function ($country, $fulfillment, $items) use ($secondId) {
            return $country === 'sv' && count($items) === 1 && (int) $items[0]['key'] === (int) $secondId;
        })->andReturn([
            'ok' => true,
            'message' => 'Checkout validado correctamente.',
            'lines' => [[
                'key' => (string) $secondId, 'sku' => 'SKU10', 'name' => 'SKU10', 'size' => 'M',
                'requestedQuantity' => 1, 'availableQuantity' => 2, 'ok' => true, 'message' => 'Stock suficiente.',
            ]],
        ]);

        $validation = $this->service->validateForCheckout('sv', $this->visitor, null, true);

        $this->assertTrue($validation['ok']);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_id' => $firstId, 'cad_seleccionado' => 0]);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_id' => $secondId, 'cad_seleccionado' => 1]);
    }

    public function test_mobile_checkout_splits_unselected_lines_into_an_active_cart(): void
    {
        $first = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $second = $this->service->add('sv', $this->visitor, null, $this->item('M', 2));
        $firstId = $first['cart']['items'][0]['id'];
        $secondId = collect($second['cart']['items'])->firstWhere('size', 'M')['id'];
        DB::table('stj_carrito_detalles')->where('cad_id', $firstId)->update(['cad_seleccionado' => 0]);

        $result = $this->service->startCheckout('sv', $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(),
            '_selected_only' => true,
        ]);

        $checkoutCartId = $result['cart']['id'];
        $activeCartId = DB::table('stj_carritos')->where('car_estado', 'ACTIVO')->value('car_id');
        $this->assertNotSame((int) $checkoutCartId, (int) $activeCartId);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_id' => $secondId, 'cad_carrito_id' => $checkoutCartId]);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_id' => $firstId, 'cad_carrito_id' => $activeCartId]);
        $this->assertCount(1, $result['checkout']['lines']);
        $this->assertSame((int) $secondId, (int) $result['checkout']['lines'][0]['itemId']);
    }

    public function test_checkout_start_uses_the_same_central_promotion_resolution_as_the_cart(): void
    {
        $this->promotion(103, 'DESCUENTO-SKU');
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => 103,
            'ppr_producto' => 10,
            'ppr_descuento' => 25,
        ]);
        $cart = $this->service->add('sv', $this->visitor, null, $this->item('S', 2));

        $result = $this->service->startCheckout('sv', $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(),
        ]);

        $this->assertEquals($cart['cart']['totals']['baseSubtotal'], $result['checkout']['baseSubtotal']);
        $this->assertEquals($cart['cart']['totals']['discount'], $result['checkout']['discount']);
        $this->assertEquals($cart['cart']['totals']['total'], $result['checkout']['subtotal']);
        $this->assertEquals(25.0, $result['checkout']['discountPercentage']);
        $this->assertEquals(150.0, $result['checkout']['total']);
        $this->assertSame(103, $result['checkout']['lines'][0]['promotion']['id']);
        $this->assertSame('25% de descuento', $result['checkout']['lines'][0]['promotion']['benefitLabel']);
    }

    public function test_checkout_start_resolves_two_for_one_from_the_complete_basket(): void
    {
        $this->promotion(104, 'CONDICION-SKU', ['prm_restriccion' => '2x1']);
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => 104,
            'ppr_producto' => 10,
        ]);
        $this->service->add('sv', $this->visitor, null, $this->item('S', 2));

        $result = $this->service->startCheckout('sv', $this->visitor, null, [
            'operation_uuid' => (string) Str::uuid(),
        ]);

        $this->assertEquals(200.0, $result['checkout']['baseSubtotal']);
        $this->assertEquals(100.0, $result['checkout']['discount']);
        $this->assertEquals(50.0, $result['checkout']['discountPercentage']);
        $this->assertEquals(100.0, $result['checkout']['subtotal']);
        $this->assertSame('Aplica 2x1', $result['checkout']['lines'][0]['promotion']['benefitLabel']);
    }

    public function test_cart_without_fulfillment_context_cannot_start_checkout(): void
    {
        $result = $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        DB::table('stj_carritos')->where('car_id', $result['cart']['id'])->update(['car_tienda_id' => null, 'car_tienda_codigo_snapshot' => null, 'car_inventory_source' => null]);
        $this->expectException(ValidationException::class);
        $this->service->startCheckout('sv', $this->visitor, null, ['operation_uuid' => (string) Str::uuid()]);
    }

    private function item(string $size, int $quantity): array
    {
        return ['operation_uuid' => (string) Str::uuid(), 'product_id' => 10, 'sku' => 'SKU10', 'size' => $size, 'quantity' => $quantity];
    }

    private function promotion(int $id, string $type, array $overrides = []): void
    {
        DB::table('stj_promociones')->insert([
            ...[
                'prm_id' => $id,
                'prm_pais' => 1,
                'prm_nombre' => "Promoción {$id}",
                'prm_nombre_comercial' => "Promoción {$id}",
                'prm_tipo' => 'SKU',
                'prm_tipo_promocion' => $type,
                'prm_restriccion' => null,
                'prm_porcentaje' => null,
                'prm_precio' => null,
                'prm_tipo_checkout' => 'TODO',
                'prm_alcance_tienda' => 'TODAS',
                'prm_aplica' => 'TODO',
                'prm_estado' => 'EN-PROCESO',
                'prm_modalidad' => 'PROGRAMADO',
                'prm_origen' => 'WEB',
            ],
            ...$overrides,
        ]);
        DB::table('stj_promociones_horario')->insert([
            'pho_promocion' => $id,
            'pho_tipo' => 'NORMAL',
            'pho_inicio' => now()->subHour(),
            'pho_fin' => now()->addHour(),
            'pho_estado' => 'ACTIVO',
        ]);
    }
}
