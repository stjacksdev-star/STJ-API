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
}
