<?php

namespace Tests\Feature;

use App\Services\StorefrontStoreService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontStoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_paises', fn (Blueprint $table) => tap($table->bigInteger('pai_id', true), fn () => $table->string('pai_codigo', 3)));
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->bigInteger('tie_id', true);
            $table->string('tie_codigo', 15);
            $table->string('tie_nombre');
            $table->string('tie_direccion');
            $table->string('tie_zona')->nullable();
            $table->string('tie_latitud')->nullable();
            $table->string('tie_longitud')->nullable();
            $table->text('tie_horario')->nullable();
            $table->bigInteger('tie_pais');
            $table->boolean('tie_productos');
        });
        Schema::create('stj_tiendas_horario', function (Blueprint $table) {
            $table->bigInteger('tih', true);
            $table->bigInteger('tih_pais');
            $table->string('tih_tienda', 10);
            $table->integer('tih_dia');
            $table->time('tih_inicio')->nullable();
            $table->time('tih_fin')->nullable();
            $table->string('tih_open');
        });
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_tiendas')->insert([
            ['tie_codigo' => 'A', 'tie_nombre' => 'Lejana', 'tie_direccion' => 'Direccion A', 'tie_zona' => 'Centro', 'tie_latitud' => '13.80', 'tie_longitud' => '-89.30', 'tie_horario' => 'Horario A', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_codigo' => 'B', 'tie_nombre' => 'Cercana', 'tie_direccion' => 'Direccion B', 'tie_zona' => 'Centro', 'tie_latitud' => '13.70', 'tie_longitud' => '-89.20', 'tie_horario' => 'Horario B', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_codigo' => 'X', 'tie_nombre' => 'Sin productos', 'tie_direccion' => 'Direccion X', 'tie_zona' => null, 'tie_latitud' => null, 'tie_longitud' => null, 'tie_horario' => null, 'tie_pais' => 1, 'tie_productos' => 0],
        ]);
        DB::table('stj_tiendas_horario')->insert(['tih_pais' => 1, 'tih_tienda' => 'B', 'tih_dia' => 1, 'tih_inicio' => '08:00:00', 'tih_fin' => '18:00:00', 'tih_open' => 'SI']);
        config(['inventory.domicilio_store_by_country.sv' => '57']);
    }

    public function test_it_returns_delivery_and_product_stores_ordered_by_distance(): void
    {
        Carbon::setTestNow('2026-07-20 10:00:00');
        $result = app(StorefrontStoreService::class)->forCountry('sv', 13.70, -89.20);
        $this->assertSame('DOMICILIO', $result['services'][0]['type']);
        $this->assertSame('Cercana', $result['stores'][0]['name']);
        $this->assertTrue($result['stores'][0]['schedule']['isOpen']);
        $this->assertCount(2, $result['stores']);
        Carbon::setTestNow();
    }
}
