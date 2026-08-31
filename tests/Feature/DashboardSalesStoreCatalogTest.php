<?php

namespace Tests\Feature;

use App\Services\Dashboard\SalesKpiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardSalesStoreCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_only_returns_product_enabled_stores_for_selected_country(): void
    {
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->integer('pai_id')->primary();
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });

        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->integer('tie_id')->primary();
            $table->integer('tie_pais');
            $table->string('tie_codigo');
            $table->string('tie_nombre');
            $table->boolean('tie_productos');
        });

        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador'],
            ['pai_id' => 2, 'pai_codigo' => 'GT', 'pai_nombre' => 'Guatemala'],
        ]);

        DB::table('stj_tiendas')->insert([
            ['tie_id' => 1, 'tie_pais' => 1, 'tie_codigo' => '001', 'tie_nombre' => 'Activa SV', 'tie_productos' => 1],
            ['tie_id' => 2, 'tie_pais' => 1, 'tie_codigo' => '002', 'tie_nombre' => 'Inactiva SV', 'tie_productos' => 0],
            ['tie_id' => 3, 'tie_pais' => 2, 'tie_codigo' => '003', 'tie_nombre' => 'Activa GT', 'tie_productos' => 1],
        ]);

        $catalog = app(SalesKpiService::class)->catalog('1');

        $this->assertSame([1], array_column($catalog['stores'], 'storeId'));
        $this->assertSame(['001'], array_column($catalog['stores'], 'storeCode'));
    }
}
