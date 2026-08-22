<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileCategoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->bigInteger('pai_id')->primary();
            $table->string('pai_codigo', 3);
        });

        Schema::create('stj_categorias', function (Blueprint $table) {
            $table->bigInteger('cat_id')->primary();
            $table->boolean('cat_habilitado_app');
            $table->integer('cat_orden_app');
            $table->string('cat_nombre_app')->nullable();
            $table->string('cat_logo_app')->nullable();
            $table->string('cat_tallas')->nullable();
        });

        Schema::create('stj_sub_categorias', function (Blueprint $table) {
            $table->bigInteger('sca_id')->primary();
            $table->bigInteger('sca_categoria');
            $table->string('sca_nombre');
        });

        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_categorias')->insert([
            ['cat_id' => 2, 'cat_habilitado_app' => 1, 'cat_orden_app' => 2, 'cat_nombre_app' => 'Niña', 'cat_logo_app' => 'nina.png', 'cat_tallas' => '2-12'],
            ['cat_id' => 1, 'cat_habilitado_app' => 1, 'cat_orden_app' => 1, 'cat_nombre_app' => 'Niño', 'cat_logo_app' => 'nino.png', 'cat_tallas' => '2-14'],
            ['cat_id' => 10, 'cat_habilitado_app' => 1, 'cat_orden_app' => 3, 'cat_nombre_app' => 'Excluida búsqueda', 'cat_logo_app' => 'diez.png', 'cat_tallas' => null],
            ['cat_id' => 11, 'cat_habilitado_app' => 1, 'cat_orden_app' => 4, 'cat_nombre_app' => 'Categoría once', 'cat_logo_app' => 'once.png', 'cat_tallas' => null],
            ['cat_id' => 4, 'cat_habilitado_app' => 0, 'cat_orden_app' => 5, 'cat_nombre_app' => 'Inactiva', 'cat_logo_app' => 'inactiva.png', 'cat_tallas' => null],
        ]);
        DB::table('stj_sub_categorias')->insert([
            ['sca_id' => 20, 'sca_categoria' => 1, 'sca_nombre' => 'Zapatos'],
            ['sca_id' => 10, 'sca_categoria' => 1, 'sca_nombre' => 'Camisas'],
            ['sca_id' => 30, 'sca_categoria' => 2, 'sca_nombre' => 'Vestidos'],
        ]);

        config(['mobile.legacy_category_asset_url' => 'https://assets.example/categories']);
    }

    public function test_it_returns_the_legacy_category_contract_in_app_order(): void
    {
        $this->getJson('/api/mobile/v1/catalog/categories?countryId=1')
            ->assertOk()
            ->assertExactJson([
                'records' => [
                    [
                        'id' => 1,
                        'nombre' => '<span style="color:rgb(0,122,201)">&nbsp;</span>',
                        'foto' => 'https://assets.example/categories/nino.png',
                        'tallas' => '2-14',
                        'subCategorias' => [
                            ['id' => 10, 'nombre' => 'Camisas'],
                            ['id' => 20, 'nombre' => 'Zapatos'],
                        ],
                    ],
                    [
                        'id' => 2,
                        'nombre' => '<span style="color:rgb(0,122,201)">&nbsp;</span>',
                        'foto' => 'https://assets.example/categories/nina.png',
                        'tallas' => '2-12',
                        'subCategorias' => [
                            ['id' => 30, 'nombre' => 'Vestidos'],
                        ],
                    ],
                    [
                        'id' => 10,
                        'nombre' => '<span style="color:rgb(0,122,201)">&nbsp;</span>',
                        'foto' => 'https://assets.example/categories/diez.png',
                        'tallas' => null,
                        'subCategorias' => [],
                    ],
                ],
            ]);
    }

    public function test_it_requires_a_supported_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/categories')->assertUnprocessable();
        $this->getJson('/api/mobile/v1/catalog/categories?countryId=99')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('countryId');
    }

    public function test_it_returns_the_legacy_search_category_contract(): void
    {
        $response = $this->getJson('/api/mobile/v1/catalog/categories/search?countryId=1');

        $response->assertOk()
            ->assertJsonCount(3, 'records')
            ->assertJsonPath('records.0.id', 1)
            ->assertJsonPath('records.0.nombre', '<span style="color:rgb(0,122,201)">Niño</span>')
            ->assertJsonPath('records.0.foto2', 'https://assets.example/categories/ocho2/1.jpg')
            ->assertJsonPath('records.0.foto', 'https://assets.example/categories/nino.png')
            ->assertJsonPath('records.0.subCategorias.0.nombre', 'Camisas')
            ->assertJsonPath('records.2.id', 11);

        $this->assertNotContains(10, collect($response->json('records'))->pluck('id')->all());
    }
}
