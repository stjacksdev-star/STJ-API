<?php

namespace Tests\Feature;

use App\Services\ProductMetricsCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductMetricsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        DB::table('stj_productos')->insert([
            ['pro_id' => 10], ['pro_id' => 20], ['pro_id' => 30], ['pro_id' => 40], ['pro_id' => 50], ['pro_id' => 60],
        ]);
        DB::table('stj_producto_pais')->insert([
            ['ppa_producto' => 10, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 20, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 30, 'ppa_pais' => 2, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 40, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 50, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
            ['ppa_producto' => 60, 'ppa_pais' => 1, 'ppa_estado' => 'ACTIVO'],
        ]);
    }

    public function test_it_builds_a_single_sales_and_views_snapshot_with_stable_rankings(): void
    {
        $this->recordView(10, 1, now()->subDay());
        $this->recordView(10, 1, now()->subDays(2));
        $this->recordView(10, 1, now()->subDays(10));
        $this->recordView(10, 1, now()->subDays(20));
        $this->recordView(10, 1, now()->subDays(40));
        $this->recordView(20, 1, now()->subDay());
        $this->recordView(20, 1, now()->subDays(2));
        $this->recordView(30, 2, now()->subDay());
        $this->recordView(10, 1, now()->subDay(), 'RECOMMENDATION_CLICK');
        $this->favorite(10, 1, now()->subDay(), visitor: 101);
        $this->favorite(10, 1, now()->subDays(2), user: 201);
        $this->favorite(20, 1, now()->subDays(10), visitor: 102);
        $this->favorite(30, 2, now()->subDay(), user: 202);
        $this->favorite(50, 1, now()->subDay(), visitor: 103);
        $firstCartAdd = (string) Str::uuid();
        $this->recordEvent(10, 1, now()->subDay(), 'ADD_TO_CART', $firstCartAdd);
        $this->recordEvent(10, 1, now()->subHours(12), 'ADD_TO_CART');
        $this->recordEvent(10, 1, now()->subDay(), 'ADD_TO_CART', $firstCartAdd, ignoreDuplicate: true);
        $this->recordEvent(20, 1, now()->subDays(10), 'ADD_TO_CART');
        $this->recordEvent(30, 2, now()->subDay(), 'ADD_TO_CART');
        $this->recordEvent(60, 1, now()->subDay(), 'ADD_TO_CART');
        $this->recordEvent(60, 1, now()->subDays(40), 'ADD_TO_CART');
        $this->sale(40, 1, 3, 12.50);

        $calculator = app(ProductMetricsCalculator::class);
        $this->assertSame(5, $calculator->calculateAndStore(1, 7));

        $this->assertMetric(10, 1, '7D', views: 2, viewRank: 1, units: 0, salesRank: null, favorites: 2, cartAdds: 2);
        $this->assertMetric(20, 1, '7D', views: 2, viewRank: 2, units: 0, salesRank: null, favorites: 0, cartAdds: 0);
        $this->assertMetric(40, 1, '7D', views: 0, viewRank: null, units: 3, salesRank: 1, favorites: 0, cartAdds: 0);
        $this->assertMetric(50, 1, '7D', views: 0, viewRank: null, units: 0, salesRank: null, favorites: 1, cartAdds: 0);
        $this->assertMetric(60, 1, '7D', views: 0, viewRank: null, units: 0, salesRank: null, favorites: 0, cartAdds: 1);
        $this->assertDatabaseMissing('stj_producto_metricas', ['pme_producto' => 30, 'pme_pais' => 1]);

        $calculator->calculateAndStore(1, 14);
        $this->assertMetric(10, 1, '14D', views: 3, viewRank: 1, units: 0, salesRank: null, favorites: 2, cartAdds: 2);
        $this->assertMetric(20, 1, '14D', views: 2, viewRank: 2, units: 0, salesRank: null, favorites: 1, cartAdds: 1);
        $calculator->calculateAndStore(1, 30);
        $this->assertMetric(10, 1, '30D', views: 4, viewRank: 1, units: 0, salesRank: null, favorites: 2, cartAdds: 2);
        $this->assertMetric(20, 1, '30D', views: 2, viewRank: 2, units: 0, salesRank: null, favorites: 1, cartAdds: 1);
        $calculator->calculateAndStore(2, 7);
        $this->assertMetric(30, 2, '7D', views: 1, viewRank: 1, units: 0, salesRank: null, favorites: 1, cartAdds: 1);
    }

    public function test_recalculation_replaces_values_without_accumulating_or_losing_sales(): void
    {
        $event = $this->recordView(10, 1, now()->subDay());
        $favorite = $this->favorite(20, 1, now()->subDay(), visitor: 101);
        $cartAdd = $this->recordEvent(50, 1, now()->subDay(), 'ADD_TO_CART');
        $this->sale(40, 1, 2, 15);
        $calculator = app(ProductMetricsCalculator::class);
        $calculator->calculateAndStore(1, 7);

        $this->recordView(10, 1, now()->subHours(2));
        $calculator->calculateAndStore(1, 7);
        $this->assertMetric(10, 1, '7D', views: 2, viewRank: 1, units: 0, salesRank: null, favorites: 0, cartAdds: 0);
        $this->assertMetric(20, 1, '7D', views: 0, viewRank: null, units: 0, salesRank: null, favorites: 1, cartAdds: 0);
        $this->assertMetric(40, 1, '7D', views: 0, viewRank: null, units: 2, salesRank: 1, favorites: 0, cartAdds: 0);
        $this->assertMetric(50, 1, '7D', views: 0, viewRank: null, units: 0, salesRank: null, favorites: 0, cartAdds: 1);

        DB::table('stj_cliente_eventos')->where('cev_event_uuid', $event)->delete();
        DB::table('stj_favoritos')->where('fav_id', $favorite)->delete();
        DB::table('stj_cliente_eventos')->where('cev_event_uuid', $cartAdd)->delete();
        $calculator->calculateAndStore(1, 7);
        $this->assertMetric(10, 1, '7D', views: 1, viewRank: 1, units: 0, salesRank: null, favorites: 0, cartAdds: 0);
        $this->assertDatabaseMissing('stj_producto_metricas', ['pme_producto' => 20, 'pme_pais' => 1, 'pme_periodo' => '7D']);
        $this->assertDatabaseMissing('stj_producto_metricas', ['pme_producto' => 50, 'pme_pais' => 1, 'pme_periodo' => '7D']);
        $this->assertMetric(40, 1, '7D', views: 0, viewRank: null, units: 2, salesRank: 1, favorites: 0, cartAdds: 0);
    }

    private function recordView(int $product, int $country, mixed $occurredAt, string $type = 'PRODUCT_VIEW'): string
    {
        return $this->recordEvent($product, $country, $occurredAt, $type);
    }

    private function recordEvent(int $product, int $country, mixed $occurredAt, string $type, ?string $uuid = null, bool $ignoreDuplicate = false): string
    {
        $uuid ??= (string) Str::uuid();
        $query = DB::table('stj_cliente_eventos');
        $query->{$ignoreDuplicate ? 'insertOrIgnore' : 'insert'}([
            'cev_event_uuid' => $uuid,
            'cev_pais_id' => $country,
            'cev_producto_id' => $product,
            'cev_tipo' => $type,
            'cev_ocurrido_en' => $occurredAt,
        ]);

        return $uuid;
    }

    private function sale(int $product, int $country, int $quantity, float $price): void
    {
        DB::table('stj_pedidos')->insert(['ped_id' => 1, 'ped_id_pais' => $country]);
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => 1, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'REF1', 'ppa_fecha' => now()->subDay()]);
        DB::table('stj_pedidos_detalle')->insert(['car_ref' => 'REF1', 'car_pais' => $country, 'car_producto' => $product, 'car_accion' => 'AGREGADO', 'car_cantidad' => $quantity, 'car_precio' => $price, 'car_descuento' => 0]);
    }

    private function favorite(int $product, int $country, mixed $createdAt, ?int $visitor = null, ?int $user = null): int
    {
        return (int) DB::table('stj_favoritos')->insertGetId([
            'fav_pais' => $country,
            'fav_visitante' => $visitor,
            'fav_usuario' => $user,
            'fav_producto' => $product,
            'fav_origen' => 'WEB',
            'fav_created_at' => $createdAt,
            'fav_updated_at' => $createdAt,
        ]);
    }

    private function assertMetric(int $product, int $country, string $period, int $views, ?int $viewRank, int $units, ?int $salesRank, int $favorites, int $cartAdds): void
    {
        $row = DB::table('stj_producto_metricas')->where(['pme_producto' => $product, 'pme_pais' => $country, 'pme_periodo' => $period])->first();
        $this->assertNotNull($row);
        $this->assertSame($views, (int) $row->pme_vistas);
        $this->assertSame($viewRank, $row->pme_ranking_vistas === null ? null : (int) $row->pme_ranking_vistas);
        $this->assertSame($units, (int) $row->pme_ventas_unidades);
        $this->assertSame($salesRank, $row->pme_ranking_ventas === null ? null : (int) $row->pme_ranking_ventas);
        $this->assertSame($favorites, (int) $row->pme_favoritos);
        $this->assertSame($cartAdds, (int) $row->pme_agregados_carrito);
    }

    private function schema(): void
    {
        Schema::dropIfExists('stj_cliente_eventos');
        Schema::create('stj_productos', fn (Blueprint $table) => $table->unsignedBigInteger('pro_id')->primary());
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->unsignedBigInteger('ppa_producto');
            $table->unsignedInteger('ppa_pais');
            $table->string('ppa_estado');
        });
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->unsignedBigInteger('ped_id')->primary();
            $table->unsignedInteger('ped_id_pais');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->unsignedBigInteger('ppa_pedido');
            $table->string('ppa_estado');
            $table->string('ppa_ref');
            $table->dateTime('ppa_fecha');
        });
        Schema::create('stj_pedidos_detalle', function (Blueprint $table) {
            $table->string('car_ref');
            $table->unsignedInteger('car_pais');
            $table->unsignedBigInteger('car_producto');
            $table->string('car_accion');
            $table->unsignedInteger('car_cantidad');
            $table->decimal('car_precio', 12, 2);
            $table->decimal('car_descuento', 5, 2)->nullable();
        });
        Schema::create('stj_cliente_eventos', function (Blueprint $table) {
            $table->uuid('cev_event_uuid')->unique();
            $table->unsignedInteger('cev_pais_id');
            $table->unsignedBigInteger('cev_producto_id')->nullable();
            $table->string('cev_tipo');
            $table->dateTime('cev_ocurrido_en');
        });
        Schema::create('stj_producto_metricas', function (Blueprint $table) {
            $table->id('pme_id');
            $table->unsignedBigInteger('pme_producto');
            $table->unsignedInteger('pme_pais');
            $table->string('pme_periodo');
            $table->unsignedInteger('pme_ventas_unidades')->default(0);
            $table->unsignedInteger('pme_ventas_pedidos')->default(0);
            $table->decimal('pme_monto_vendido', 12, 2)->default(0);
            $table->unsignedInteger('pme_vistas')->default(0);
            $table->unsignedInteger('pme_favoritos')->default(0);
            $table->unsignedInteger('pme_agregados_carrito')->default(0);
            $table->unsignedInteger('pme_ranking_ventas')->nullable();
            $table->unsignedInteger('pme_ranking_vistas')->nullable();
            $table->dateTime('pme_fecha_calculo');
            $table->unique(['pme_producto', 'pme_pais', 'pme_periodo']);
        });
    }
}
