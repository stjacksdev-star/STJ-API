<?php

namespace Tests\Feature;

use App\Services\StorefrontCouponResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontCouponResolverTest extends TestCase
{
    private StorefrontCouponResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->schema();
        $this->resolver = app(StorefrontCouponResolver::class);
    }

    public function test_price_coupon_reaches_target_without_changing_regular_price(): void
    {
        $this->coupon(1, ['che_tipo' => 'PRECIO'], ['cup_monto' => 10]);

        $result = $this->resolve([1], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 15]]);

        $this->assertSame('15.00', $result['lines'][0]['regularUnitPrice']);
        $this->assertSame('5.00', $result['lines'][0]['couponDiscount']);
        $this->assertSame('10.00', $result['lines'][0]['finalTotal']);
        $this->assertSame(33.333333, $result['lines'][0]['effectiveDiscountPercentage']);
    }

    public function test_regular_coupon_does_not_apply_over_a_promotion(): void
    {
        $this->coupon(1, ['che_aplica_promo' => 'REGULAR'], ['cup_descuento' => 20]);

        $result = $this->resolve([1], [[
            'productId' => 100, 'quantity' => 1, 'unitPrice' => 100, 'promotionDiscount' => 10,
        ]]);

        $this->assertSame('NO_APLICABLE', $result['coupons'][0]['status']);
        $this->assertSame('SIN_PRODUCTOS_ELEGIBLES', $result['coupons'][0]['reasonCode']);
        $this->assertSame('90.00', $result['lines'][0]['finalTotal']);
    }

    public function test_percentage_coupons_accumulate_additively(): void
    {
        $this->coupon(1, [], ['cup_descuento' => 20]);
        $this->coupon(2, [], ['cup_descuento' => 50]);

        $result = $this->resolve([1, 2], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 100]]);

        $this->assertSame('70.00', $result['totals']['couponDiscount']);
        $this->assertSame('30.00', $result['totals']['final']);
        $this->assertCount(2, $result['lines'][0]['coupons']);
    }

    public function test_regular_coupons_accumulate_only_on_lines_without_promotions(): void
    {
        $this->coupon(1, ['che_aplica_promo' => 'REGULAR'], ['cup_descuento' => 20]);
        $this->coupon(2, ['che_aplica_promo' => 'REGULAR'], ['cup_descuento' => 10]);

        $result = $this->resolve([1, 2], [
            ['productId' => 100, 'quantity' => 1, 'unitPrice' => 100],
            ['productId' => 101, 'quantity' => 1, 'unitPrice' => 100, 'promotionDiscount' => 25],
        ]);

        $this->assertSame('30.00', $result['lines'][0]['couponDiscount']);
        $this->assertSame('70.00', $result['lines'][0]['finalTotal']);
        $this->assertSame('0.00', $result['lines'][1]['couponDiscount']);
        $this->assertSame('75.00', $result['lines'][1]['finalTotal']);
    }

    public function test_all_products_coupon_accumulates_after_the_promotion(): void
    {
        $this->coupon(1, ['che_aplica_promo' => 'TODOS'], ['cup_descuento' => 20]);
        $this->coupon(2, ['che_aplica_promo' => 'TODOS'], ['cup_descuento' => 10]);

        $result = $this->resolve([1, 2], [[
            'productId' => 100, 'quantity' => 1, 'unitPrice' => 100, 'promotionDiscount' => 20,
        ]]);

        $this->assertSame('24.00', $result['lines'][0]['couponDiscount']);
        $this->assertSame('56.00', $result['lines'][0]['finalTotal']);
        $this->assertSame(44.0, $result['lines'][0]['effectiveDiscountPercentage']);
    }

    public function test_discounts_can_never_reach_one_hundred_percent(): void
    {
        $this->coupon(1, [], ['cup_descuento' => 99.99]);
        $this->coupon(2, [], ['cup_descuento' => 99.99]);

        $result = $this->resolve([1, 2], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 1]]);

        $this->assertSame('0.99', $result['totals']['couponDiscount']);
        $this->assertSame('0.01', $result['totals']['final']);
        $this->assertLessThan(100, $result['lines'][0]['effectiveDiscountPercentage']);
    }

    public function test_personal_coupon_requires_matching_normalized_email(): void
    {
        $this->coupon(1, ['che_generico' => 'NO'], ['cup_correo' => 'Client@Example.com', 'cup_descuento' => 10]);

        $valid = $this->resolve([1], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 20]], ['email' => ' client@example.com ']);
        $invalid = $this->resolve([1], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 20]], ['email' => 'other@example.com']);

        $this->assertSame('APLICADO', $valid['coupons'][0]['status']);
        $this->assertSame('CORREO_NO_COINCIDE', $invalid['coupons'][0]['reasonCode']);
    }

    public function test_checkout_country_minimum_and_first_purchase_are_revalidated(): void
    {
        $this->coupon(1, [
            'che_checkout' => 'DOMICILIO', 'che_pais' => 1, 'che_aplica_monto_minimo' => 'SI',
            'che_monto_minimo' => 50, 'che_solo_primera_compra' => 'SI',
        ], ['cup_descuento' => 10]);

        $minimum = $this->resolve([1], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 40]]);
        $purchase = $this->resolve([1], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 60]], ['hasApprovedOrder' => true]);
        $store = $this->resolve([1], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 60]], ['checkoutType' => 'TIENDA']);

        $this->assertSame('MONTO_MINIMO_NO_ALCANZADO', $minimum['coupons'][0]['reasonCode']);
        $this->assertSame('PRIMERA_COMPRA_REQUERIDA', $purchase['coupons'][0]['reasonCode']);
        $this->assertSame('CHECKOUT_NO_PERMITIDO', $store['coupons'][0]['reasonCode']);
    }

    public function test_product_rule_has_precedence_over_coupon_and_header(): void
    {
        $this->coupon(1, ['che_tipo_productos' => 'PLA', 'che_descuento' => 10], ['cup_descuento' => 20]);
        DB::table('stj_cupones_producto')->insert(['cpr_cupon' => 1, 'cpr_producto' => 100, 'cpr_descuento' => 30, 'cpr_precio' => null]);

        $result = $this->resolve([1], [
            ['productId' => 100, 'quantity' => 1, 'unitPrice' => 100],
            ['productId' => 101, 'quantity' => 1, 'unitPrice' => 100],
        ]);

        $this->assertSame('30.00', $result['lines'][0]['couponDiscount']);
        $this->assertSame('0.00', $result['lines'][1]['couponDiscount']);
    }

    public function test_free_shipping_only_applies_once_for_home_delivery(): void
    {
        $this->coupon(1, ['che_tipo' => 'ENVIO_GRATIS']);
        $this->coupon(2, ['che_tipo' => 'ENVIO_GRATIS']);

        $result = $this->resolve([1, 2], [['productId' => 100, 'quantity' => 1, 'unitPrice' => 20]], ['shipping' => 4]);

        $this->assertSame('4.00', $result['totals']['shippingDiscount']);
        $this->assertSame('APLICADO', $result['coupons'][0]['status']);
        $this->assertSame('NO_APLICABLE', $result['coupons'][1]['status']);
    }

    private function resolve(array $ids, array $lines, array $overrides = []): array
    {
        return $this->resolver->resolve([
            'countryId' => 1,
            'checkoutType' => 'DOMICILIO',
            'email' => 'client@example.com',
            'couponIds' => $ids,
            'at' => '2026-08-13 12:00:00',
            'shipping' => 0,
            'lines' => $lines,
            ...$overrides,
        ]);
    }

    private function coupon(int $id, array $header = [], array $detail = []): void
    {
        DB::table('stj_cupones_header')->insert([
            ...[
                'che_id' => $id, 'che_nombre' => "Cupón {$id}", 'che_tipo' => 'DESCUENTO', 'che_aplica' => 'WEB',
                'che_checkout' => 'TODO', 'che_generico' => 'SI', 'che_pais' => 1,
                'che_inicio' => '2026-08-01 00:00:00', 'che_final' => '2026-08-31 23:59:59',
                'che_monto' => 0, 'che_descuento' => 0, 'che_aplica_monto_minimo' => 'NO', 'che_monto_minimo' => 0,
                'che_multiple' => 'NO', 'che_aplica_promo' => 'TODOS', 'che_solo_primera_compra' => 'NO',
                'che_estado' => 'ACTIVO', 'che_tipo_productos' => 'NA',
            ],
            ...$header,
        ]);
        DB::table('stj_cupones')->insert([
            ...[
                'cup_id' => $id, 'cup_header' => $id, 'cup_codigo' => "CODE{$id}", 'cup_estado' => 'ACTIVO',
                'cup_monto' => null, 'cup_descuento' => null, 'cup_correo' => null,
            ],
            ...$detail,
        ]);
    }

    private function schema(): void
    {
        Schema::create('stj_cupones_header', function (Blueprint $table) {
            $table->id('che_id');
            $table->string('che_nombre');
            $table->string('che_tipo');
            $table->string('che_aplica');
            $table->string('che_checkout');
            $table->string('che_generico');
            $table->unsignedBigInteger('che_pais');
            $table->dateTime('che_inicio')->nullable();
            $table->dateTime('che_final')->nullable();
            $table->decimal('che_monto')->nullable();
            $table->decimal('che_descuento')->nullable();
            $table->string('che_aplica_monto_minimo')->nullable();
            $table->decimal('che_monto_minimo')->nullable();
            $table->string('che_multiple')->nullable();
            $table->string('che_aplica_promo')->nullable();
            $table->string('che_solo_primera_compra')->nullable();
            $table->string('che_estado');
            $table->string('che_tipo_productos')->nullable();
        });
        Schema::create('stj_cupones', function (Blueprint $table) {
            $table->id('cup_id');
            $table->unsignedBigInteger('cup_header');
            $table->string('cup_codigo');
            $table->string('cup_estado');
            $table->decimal('cup_monto')->nullable();
            $table->decimal('cup_descuento')->nullable();
            $table->string('cup_correo')->nullable();
        });
        Schema::create('stj_cupones_producto', function (Blueprint $table) {
            $table->id('cpr_id');
            $table->unsignedBigInteger('cpr_cupon');
            $table->unsignedBigInteger('cpr_producto');
            $table->decimal('cpr_descuento')->nullable();
            $table->decimal('cpr_precio')->nullable();
        });
    }
}
