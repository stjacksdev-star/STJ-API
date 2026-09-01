<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileLocationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table): void {
            $table->unsignedBigInteger('pai_id')->primary();
            $table->unsignedBigInteger('pai_id_world')->nullable();
        });

        Schema::create('stj_world_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('country_id');
            $table->string('name');
            $table->unsignedTinyInteger('estado')->default(1);
        });

        Schema::create('stj_world_cities', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('state_id');
            $table->unsignedBigInteger('country_id');
            $table->string('name');
        });

        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_id_world' => 66],
            ['pai_id' => 2, 'pai_id_world' => 90],
        ]);

        DB::table('stj_world_states')->insert([
            ['id' => 3, 'country_id' => 66, 'name' => 'San Salvador', 'estado' => 1],
            ['id' => 2, 'country_id' => 66, 'name' => 'La Libertad', 'estado' => 1],
            ['id' => 4, 'country_id' => 66, 'name' => 'Inactivo', 'estado' => 0],
            ['id' => 5, 'country_id' => 90, 'name' => 'Guatemala', 'estado' => 1],
        ]);

        DB::table('stj_world_cities')->insert([
            ['id' => 12, 'state_id' => 2, 'country_id' => 66, 'name' => 'Santa Tecla'],
            ['id' => 11, 'state_id' => 2, 'country_id' => 66, 'name' => 'Antiguo Cuscatlan'],
            ['id' => 13, 'state_id' => 3, 'country_id' => 66, 'name' => 'San Salvador'],
            ['id' => 14, 'state_id' => 5, 'country_id' => 90, 'name' => 'Guatemala'],
        ]);
    }

    public function test_it_returns_active_departments_for_the_storefront_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/departments?countryId=1')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => 2, 'name' => 'La Libertad'],
                    ['id' => 3, 'name' => 'San Salvador'],
                ],
                'message' => 'Success',
            ]);
    }

    public function test_it_rejects_an_unknown_storefront_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/departments?countryId=99')
            ->assertStatus(422)
            ->assertJsonPath('errors.countryId.0', 'Pais no soportado.');
    }

    public function test_it_returns_municipalities_for_a_state_in_the_selected_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/municipalities?countryId=1&stateId=2')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => 11, 'name' => 'Antiguo Cuscatlan'],
                    ['id' => 12, 'name' => 'Santa Tecla'],
                ],
                'message' => 'Success',
            ]);
    }

    public function test_it_rejects_a_state_from_another_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/municipalities?countryId=1&stateId=5')
            ->assertStatus(422)
            ->assertJsonPath('errors.stateId.0', 'El departamento no pertenece al pais seleccionado.');
    }
}
