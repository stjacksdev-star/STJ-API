<?php

namespace Tests\Feature;

use App\Exceptions\CartOperationConflict;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\Inventory\InventorySourceResolver;
use App\Services\ProductDetailAvailabilityService;
use App\Services\StorefrontCartService;
use App\Services\StorefrontFulfillmentService;
use App\Services\StorefrontProductPricingService;
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

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_paises', fn (Blueprint $t) => tap($t->bigInteger('pai_id', true), fn () => $t->string('pai_codigo', 3)));
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
        Schema::create('stj_promociones', fn (Blueprint $t) => $t->bigInteger('prm_id', true));
        DB::table('stj_paises')->insert([['pai_id' => 1, 'pai_codigo' => 'SV'], ['pai_id' => 2, 'pai_codigo' => 'GT']]);
        DB::table('stj_productos')->insert(['pro_id' => 10, 'pro_codigo' => 'SKU10', 'pro_nombre' => 'Producto', 'pro_tallas' => 'S,M', 'pro_estatus' => 'ACTIVO']);
        DB::table('stj_producto_pais')->insert([['ppa_pais' => 1, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 100, 'ppa_precio_talla' => 'NO', 'ppa_descuento' => 10, 'ppa_origen_descuento' => 'WEB', 'ppa_promo_nombre' => 'Promo'], ['ppa_pais' => 2, 'ppa_producto' => 10, 'ppa_estado' => 'ACTIVO', 'ppa_precio' => 200, 'ppa_precio_talla' => 'NO', 'ppa_descuento' => null, 'ppa_origen_descuento' => null, 'ppa_promo_nombre' => null]]);
        DB::table('stj_tiendas')->insert([['tie_id' => 1, 'tie_codigo' => '57', 'tie_nombre' => 'Domicilio SV', 'tie_pais' => 1, 'tie_productos' => 0], ['tie_id' => 2, 'tie_codigo' => '002', 'tie_nombre' => 'Las Cascadas', 'tie_pais' => 1, 'tie_productos' => 1], ['tie_id' => 3, 'tie_codigo' => '2', 'tie_nombre' => 'Domicilio GT', 'tie_pais' => 2, 'tie_productos' => 0]]);
        config(['inventory.domicilio_store_by_country.sv' => '57', 'inventory.domicilio_store_by_country.gt' => '2']);
        $this->visitor = StorefrontVisitor::query()->create(['vis_uuid' => (string) Str::uuid(), 'vis_origen' => 'WEB', 'vis_pais_id' => 1, 'vis_primera_visita' => now(), 'vis_ultima_visita' => now(), 'vis_expira_en' => now()->addYear(), 'vis_creado_en' => now(), 'vis_actualizado_en' => now()]);
        $availability = Mockery::mock(ProductDetailAvailabilityService::class);
        $availability->shouldReceive('forCountryAndSlug')->andReturnUsing(fn () => ['sizes' => [['size' => 'S', 'quantityInActiveStore' => 5], ['size' => 'M', 'quantityInActiveStore' => 2]]]);
        $this->service = new StorefrontCartService($availability, new StorefrontProductPricingService, new StorefrontFulfillmentService(new InventorySourceResolver));
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

    public function test_same_variant_updates_and_other_size_creates_line(): void
    {
        $this->service->add('sv', $this->visitor, null, $this->item('S', 1));
        $this->service->add('sv', $this->visitor, null, $this->item('S', 2));
        $this->service->add('sv', $this->visitor, null, $this->item('M', 1));
        $this->assertDatabaseCount('stj_carrito_detalles', 2);
        $this->assertDatabaseHas('stj_carrito_detalles', ['cad_talla' => 'S', 'cad_cantidad' => 3]);
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

    private function item(string $size, int $quantity): array
    {
        return ['operation_uuid' => (string) Str::uuid(), 'product_id' => 10, 'sku' => 'SKU10', 'size' => $size, 'quantity' => $quantity];
    }
}
