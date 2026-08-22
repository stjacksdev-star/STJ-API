<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileStoreEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->bigInteger('pai_id')->primary();
            $table->string('pai_codigo', 3);
        });

        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->bigInteger('tie_id', true);
            $table->string('tie_codigo', 15);
            $table->string('tie_nombre');
            $table->string('tie_telefono')->nullable();
            $table->text('tie_horario')->nullable();
            $table->string('tie_correo')->nullable();
            $table->string('tie_direccion')->nullable();
            $table->bigInteger('tie_pais');
            $table->boolean('tie_productos');
        });

        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV'],
            ['pai_id' => 2, 'pai_codigo' => 'GT'],
        ]);

        DB::table('stj_tiendas')->insert([
            ['tie_codigo' => '57', 'tie_nombre' => 'Bodega web', 'tie_telefono' => null, 'tie_horario' => 'WEB', 'tie_correo' => 'domicilio@example.com', 'tie_direccion' => 'Centro de distribucion', 'tie_pais' => 1, 'tie_productos' => 0],
            ['tie_codigo' => '001', 'tie_nombre' => 'Galerias', 'tie_telefono' => '2200-0001', 'tie_horario' => '9:00 a 18:00', 'tie_correo' => 'galerias@example.com', 'tie_direccion' => 'San Salvador', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_codigo' => '999', 'tie_nombre' => 'Inactiva para productos', 'tie_telefono' => null, 'tie_horario' => null, 'tie_correo' => null, 'tie_direccion' => null, 'tie_pais' => 1, 'tie_productos' => 0],
            ['tie_codigo' => '2', 'tie_nombre' => 'Domicilio Guatemala', 'tie_telefono' => null, 'tie_horario' => 'WEB', 'tie_correo' => null, 'tie_direccion' => 'Guatemala', 'tie_pais' => 2, 'tie_productos' => 1],
        ]);

        config([
            'inventory.domicilio_store_by_country.sv' => '57',
            'inventory.domicilio_store_by_country.gt' => '2',
        ]);
    }

    public function test_it_returns_the_legacy_mobile_contract_with_dynamic_product_stores(): void
    {
        $response = $this->getJson('/api/mobile/v1/catalog/stores?countryId=1');

        $response->assertOk()->assertExactJson([
            'records' => [
                [
                    'id' => '57',
                    'nombre' => 'Domicilio',
                    'telefono' => '',
                    'horario' => 'WEB',
                    'correo' => 'domicilio@example.com',
                    'direccion' => 'Centro de distribucion',
                    'tipo' => 'Domicilio',
                ],
                [
                    'id' => '001',
                    'nombre' => 'Galerias',
                    'telefono' => '2200-0001',
                    'horario' => '9:00 a 18:00',
                    'correo' => 'galerias@example.com',
                    'direccion' => 'San Salvador',
                    'tipo' => 'Tienda',
                ],
            ],
        ]);

        $response->assertJsonMissing(['id' => '999']);
    }

    public function test_it_requires_a_valid_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/stores')->assertUnprocessable();
        $this->getJson('/api/mobile/v1/catalog/stores?countryId=99')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('countryId');
    }

    public function test_it_keeps_stores_isolated_by_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/stores?countryId=2')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.id', '2')
            ->assertJsonPath('records.0.tipo', 'Domicilio');
    }

    public function test_it_adds_a_delivery_option_when_the_configured_store_has_no_database_row(): void
    {
        config(['inventory.domicilio_store_by_country.gt' => 'DOM']);

        $this->getJson('/api/mobile/v1/catalog/stores?countryId=2')
            ->assertOk()
            ->assertJsonCount(2, 'records')
            ->assertJsonPath('records.0.id', 'DOM')
            ->assertJsonPath('records.0.nombre', 'Domicilio')
            ->assertJsonPath('records.0.tipo', 'Domicilio')
            ->assertJsonPath('records.1.id', '2')
            ->assertJsonPath('records.1.tipo', 'Tienda');
    }
}
