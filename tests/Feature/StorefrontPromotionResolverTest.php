<?php

namespace Tests\Feature;

use App\Services\StorefrontPromotionResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontPromotionResolverTest extends TestCase
{
    private StorefrontPromotionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        config()->set('promotions.timezone', 'America/El_Salvador');
        config()->set('promotions.conflict_deduplication_seconds', 1800);
        $this->createSchema();
        $this->resolver = app(StorefrontPromotionResolver::class);
    }

    public function test_legacy_todo_todas_applies_to_home_delivery_and_store(): void
    {
        $this->promotion(10, [
            'prm_tipo_checkout' => 'TODO',
            'prm_alcance_tienda' => 'TODAS',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(10, 100, 25);

        $home = $this->resolve('DOMICILIO');
        $store = $this->resolve('TIENDA', 1);

        $this->assertSame(10, $home['lines'][0]['promotion']['id']);
        $this->assertSame('25.00', $home['totals']['discount']);
        $this->assertSame(10, $store['lines'][0]['promotion']['id']);
        $this->assertNull($store['lines'][0]['promotion']['scopeLabel']);
    }

    public function test_gift_box_category_is_excluded_from_every_promotion_type(): void
    {
        DB::table('stj_productos')->insert([
            ['pro_id' => 170, 'pro_codigo' => 'BOX-1', 'pro_categoria' => 17],
            ['pro_id' => 100, 'pro_codigo' => 'SKU-100', 'pro_categoria' => 1],
        ]);
        $this->promotion(10, [
            'prm_tipo' => 'TODO',
            'prm_tipo_promocion' => 'DESCUENTO',
            'prm_porcentaje' => 40,
        ]);
        $this->promotion(11, [
            'prm_tipo' => 'SKU',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
            'prm_porcentaje' => 50,
        ]);
        $this->product(11, 170, 50);

        $result = $this->resolver->resolve($this->context('DOMICILIO', null, [
            ['key' => 'box', 'productId' => 170, 'quantity' => 1, 'unitPrice' => 1],
            ['key' => 'regular', 'productId' => 100, 'quantity' => 1, 'unitPrice' => 10],
        ]));
        $lines = collect($result['lines'])->keyBy('key');

        $this->assertNull($lines['box']['promotion']);
        $this->assertSame('0.00', $lines['box']['discount']);
        $this->assertNotNull($lines['regular']['promotion']);
        $this->assertSame('4.00', $lines['regular']['discount']);
    }

    public function test_country_wide_discount_does_not_require_product_relations(): void
    {
        $this->promotion(9, [
            'prm_tipo' => 'TODO',
            'prm_tipo_promocion' => 'DESCUENTO',
            'prm_porcentaje' => 15,
        ]);

        $result = $this->resolve('DOMICILIO');

        $this->assertSame(9, $result['lines'][0]['promotion']['id']);
        $this->assertSame('15.00', $result['totals']['discount']);
        $this->assertSame('15% de descuento', $result['lines'][0]['promotion']['benefitLabel']);
    }

    public function test_selected_store_promotion_only_applies_to_related_store(): void
    {
        $this->promotion(11, [
            'prm_tipo_checkout' => 'TODO',
            'prm_alcance_tienda' => 'SELECCIONADAS',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(11, 100, 20);
        DB::table('stj_promociones_tienda')->insert([
            ['prt_promocion' => 11, 'prt_tienda' => 1],
            ['prt_promocion' => 11, 'prt_tienda' => 2],
        ]);

        $applicable = $this->resolve('TIENDA', 2, 'Multiplaza');
        $notApplicable = $this->resolve('TIENDA', 3);
        $homeDelivery = $this->resolve('DOMICILIO');

        $this->assertSame(11, $applicable['lines'][0]['promotion']['id']);
        $this->assertSame('Promoción válida en tiendas seleccionadas · Válida en 2 tiendas', $applicable['lines'][0]['promotion']['availabilityLabel']);
        $this->assertNull($notApplicable['lines'][0]['promotion']);
        $this->assertNull($homeDelivery['lines'][0]['promotion']);
    }

    public function test_all_store_and_home_delivery_scopes_are_isolated(): void
    {
        $this->promotion(12, [
            'prm_tipo_checkout' => 'T',
            'prm_alcance_tienda' => 'TODAS',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(12, 100, 20);
        $this->promotion(13, [
            'prm_tipo_checkout' => 'D',
            'prm_alcance_tienda' => null,
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(13, 100, 30);

        $home = $this->resolve('DOMICILIO');
        $store = $this->resolve('TIENDA', 8);

        $this->assertSame(13, $home['lines'][0]['promotion']['id']);
        $this->assertSame(12, $store['lines'][0]['promotion']['id']);
    }

    public function test_point_price_uses_product_rule_without_global_product_country_fields(): void
    {
        $this->promotion(14, ['prm_tipo_promocion' => 'PUNTO-PRECIO']);
        $this->product(14, 100, null, 5);

        $result = $this->resolve('DOMICILIO');

        $this->assertSame('95.00', $result['totals']['discount']);
        $this->assertSame('5.00', $result['totals']['final']);
        $this->assertSame('Llévatelo por $5', $result['lines'][0]['promotion']['benefitLabel']);
    }

    public function test_two_for_one_is_evaluated_using_the_complete_basket(): void
    {
        $this->promotion(15, [
            'prm_tipo_promocion' => 'CONDICION-SKU',
            'prm_restriccion' => '2x1',
        ]);
        $this->product(15, 100);
        $this->product(15, 101);

        $result = $this->resolver->resolve($this->context('DOMICILIO', null, [
            ['key' => 'expensive', 'productId' => 100, 'quantity' => 1, 'unitPrice' => 20],
            ['key' => 'cheap', 'productId' => 101, 'quantity' => 1, 'unitPrice' => 10],
        ]));

        $this->assertSame('10.00', $result['totals']['discount']);
        $this->assertSame('20.00', $result['totals']['final']);
        $this->assertSame('6.67', $result['lines'][0]['discount']);
        $this->assertSame('13.33', $result['lines'][0]['finalTotal']);
        $this->assertSame(15, $result['lines'][0]['promotion']['id']);
        $this->assertSame('3.33', $result['lines'][1]['discount']);
        $this->assertSame('6.67', $result['lines'][1]['finalTotal']);
        $this->assertSame(15, $result['lines'][1]['promotion']['id']);
    }

    public function test_two_for_one_preview_keeps_regular_prices_and_only_exposes_the_label(): void
    {
        $this->promotion(16, [
            'prm_tipo_promocion' => 'CONDICION-SKU',
            'prm_restriccion' => '2x1',
        ]);
        $this->product(16, 100);
        $this->product(16, 101);

        $context = $this->context('DOMICILIO', null, [
            ['key' => 'first', 'productId' => 100, 'quantity' => 1, 'unitPrice' => 20],
            ['key' => 'second', 'productId' => 101, 'quantity' => 1, 'unitPrice' => 10],
        ]);
        $context['includeUntriggered'] = true;
        $result = $this->resolver->resolve($context);

        $this->assertSame('0.00', $result['totals']['discount']);
        $this->assertSame('30.00', $result['totals']['final']);
        $this->assertSame('20.00', $result['lines'][0]['finalTotal']);
        $this->assertSame('10.00', $result['lines'][1]['finalTotal']);
        $this->assertSame('Aplica 2x1', $result['lines'][0]['promotion']['benefitLabel']);
        $this->assertSame('Aplica 2x1', $result['lines'][1]['promotion']['benefitLabel']);
    }

    public function test_conditional_promotions_calculate_quantities(): void
    {
        $cases = [
            ['21/2', null, '10.00'],
            ['2doPrecio', 5, '15.00'],
            ['2xPP', 30, '10.00'],
        ];

        foreach ($cases as $index => [$restriction, $price, $expectedDiscount]) {
            $id = 20 + $index;
            $productId = 100 + $index;
            $this->promotion($id, [
                'prm_tipo_promocion' => 'CONDICION-SKU',
                'prm_restriccion' => $restriction,
                'prm_precio' => $price,
            ]);
            $this->product($id, $productId);

            $result = $this->resolver->resolve($this->context('DOMICILIO', null, [
                ['key' => "line-{$index}", 'productId' => $productId, 'quantity' => 2, 'unitPrice' => 20],
            ]));

            $this->assertSame($expectedDiscount, $result['totals']['discount']);
        }
    }

    public function test_invalid_or_expired_promotions_are_not_returned(): void
    {
        $this->promotion(30, [
            'prm_estado' => 'FINALIZADA',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(30, 100, 90);
        $this->promotion(31, [
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
            'schedule_end' => '2026-07-28 10:00:00',
        ]);
        $this->product(31, 100, 90);
        $this->promotion(32, [
            'prm_estado' => 'SUSPENDIDO',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(32, 100, 90);

        $result = $this->resolve('DOMICILIO');

        $this->assertNull($result['lines'][0]['promotion']);
        $this->assertSame('0.00', $result['totals']['discount']);
    }

    public function test_conflict_uses_scope_then_benefit_then_id_and_logs_once(): void
    {
        Log::spy();
        Cache::flush();

        $this->promotion(40, [
            'prm_tipo_checkout' => 'T',
            'prm_alcance_tienda' => 'TODAS',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(40, 100, 80);
        $this->promotion(41, [
            'prm_tipo_checkout' => 'T',
            'prm_alcance_tienda' => 'SELECCIONADAS',
            'prm_tipo_promocion' => 'DESCUENTO-SKU',
        ]);
        $this->product(41, 100, 10);
        DB::table('stj_promociones_tienda')->insert(['prt_promocion' => 41, 'prt_tienda' => 1]);

        $first = $this->resolve('TIENDA', 1);
        $second = $this->resolve('TIENDA', 1);

        $this->assertSame(41, $first['lines'][0]['promotion']['id']);
        $this->assertSame(41, $second['lines'][0]['promotion']['id']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('PROMOTION_CONFLICT', \Mockery::on(fn (array $data) => $data['selected_promotion_id'] === 41
                && $data['selection_reason'] === 'SCOPE_PRIORITY'
                && $data['candidate_promotion_ids'] === [40, 41]));
    }

    public function test_same_scope_chooses_greater_benefit_then_greater_id(): void
    {
        Log::spy();
        foreach ([[50, 20], [51, 30], [52, 30]] as [$id, $discount]) {
            $this->promotion($id, ['prm_tipo_promocion' => 'DESCUENTO-SKU']);
            $this->product($id, 100, $discount);
        }

        $result = $this->resolve('DOMICILIO');

        $this->assertSame(52, $result['lines'][0]['promotion']['id']);
        $this->assertSame('30.00', $result['totals']['discount']);
    }

    private function resolve(string $checkout, ?int $store = null, string $storeName = ''): array
    {
        return $this->resolver->resolve($this->context($checkout, $store, [
            ['key' => 'line', 'productId' => 100, 'quantity' => 1, 'unitPrice' => 100],
        ], $storeName));
    }

    private function context(string $checkout, ?int $store, array $lines, string $storeName = ''): array
    {
        return [
            'countryId' => 1,
            'checkoutType' => $checkout,
            'storeId' => $store,
            'storeName' => $storeName,
            'currencySymbol' => '$',
            'at' => '2026-07-29 12:00:00',
            'lines' => $lines,
        ];
    }

    private function promotion(int $id, array $overrides = []): void
    {
        $scheduleEnd = $overrides['schedule_end'] ?? '2026-07-30 12:00:00';
        unset($overrides['schedule_end']);
        DB::table('stj_promociones')->insert([
            ...[
                'prm_id' => $id,
                'prm_pais' => 1,
                'prm_origen' => 'WEB',
                'prm_nombre' => "Promoción {$id}",
                'prm_nombre_comercial' => "Promoción {$id}",
                'prm_modalidad' => 'PROGRAMADO',
                'prm_tipo' => 'SKU',
                'prm_estado' => 'EN-PROCESO',
                'prm_tipo_promocion' => 'DESCUENTO-SKU',
                'prm_restriccion' => null,
                'prm_porcentaje' => null,
                'prm_precio' => null,
                'prm_tipo_checkout' => 'TODO',
                'prm_alcance_tienda' => 'TODAS',
                'prm_aplica' => 'TODO',
            ],
            ...$overrides,
        ]);
        DB::table('stj_promociones_horario')->insert([
            'pho_id' => $id,
            'pho_tipo' => 'NORMAL',
            'pho_promocion' => $id,
            'pho_inicio' => '2026-07-28 12:00:00',
            'pho_fin' => $scheduleEnd,
            'pho_estado' => 'ACTIVO',
        ]);
    }

    private function product(int $promotion, int $product, ?float $discount = null, ?float $price = null): void
    {
        DB::table('stj_promociones_producto')->insert([
            'ppr_promocion' => $promotion,
            'ppr_producto' => $product,
            'ppr_descuento' => $discount,
            'ppr_precio' => $price,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->id('pro_id');
            $table->string('pro_codigo');
            $table->unsignedBigInteger('pro_categoria')->nullable();
        });
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->unsignedBigInteger('prm_pais');
            $table->string('prm_origen');
            $table->string('prm_nombre');
            $table->string('prm_nombre_comercial')->nullable();
            $table->string('prm_modalidad');
            $table->string('prm_tipo');
            $table->string('prm_estado');
            $table->string('prm_tipo_promocion');
            $table->string('prm_restriccion')->nullable();
            $table->decimal('prm_porcentaje')->nullable();
            $table->decimal('prm_precio')->nullable();
            $table->string('prm_tipo_checkout')->nullable();
            $table->string('prm_alcance_tienda')->nullable();
            $table->string('prm_aplica')->nullable();
        });
        Schema::create('stj_promociones_horario', function (Blueprint $table) {
            $table->id('pho_id');
            $table->string('pho_tipo');
            $table->unsignedBigInteger('pho_promocion');
            $table->dateTime('pho_inicio');
            $table->dateTime('pho_fin');
            $table->string('pho_estado');
        });
        Schema::create('stj_promociones_producto', function (Blueprint $table) {
            $table->id('ppr_id');
            $table->unsignedBigInteger('ppr_promocion');
            $table->unsignedBigInteger('ppr_producto');
            $table->decimal('ppr_descuento')->nullable();
            $table->decimal('ppr_precio')->nullable();
        });
        Schema::create('stj_promociones_tienda', function (Blueprint $table) {
            $table->id('prt_id');
            $table->unsignedBigInteger('prt_promocion');
            $table->unsignedBigInteger('prt_tienda');
        });
    }
}
