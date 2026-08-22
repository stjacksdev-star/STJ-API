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
            $table->string('cat_nombre');
            $table->string('cat_nombre_app')->nullable();
            $table->string('cat_logo_app')->nullable();
            $table->string('cat_tallas')->nullable();
            $table->boolean('cat_si_sub_otras')->default(false);
            $table->string('cat_sub_otras')->nullable();
        });

        Schema::create('stj_sub_categorias', function (Blueprint $table) {
            $table->bigInteger('sca_id')->primary();
            $table->bigInteger('sca_categoria');
            $table->string('sca_nombre');
            $table->string('sca_logo')->nullable();
        });
        Schema::create('stj_guia_tallas', function (Blueprint $table) {
            $table->id('gta_id');
            $table->bigInteger('gta_categoria');
            $table->integer('gta_orden');
            $table->string('gta_talla');
            $table->string('gta_peso')->nullable();
            $table->string('gta_longitud')->nullable();
            $table->string('gta_longitud_cm')->nullable();
            $table->string('gta_pecho')->nullable();
            $table->string('gta_pecho_cm')->nullable();
            $table->string('gta_cintura')->nullable();
            $table->string('gta_cintura_cm')->nullable();
            $table->string('gta_altura')->nullable();
            $table->string('gta_altura_cm')->nullable();
            $table->string('gta_cadera')->nullable();
            $table->string('gta_cadera_cm')->nullable();
        });

        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_categorias')->insert([
            ['cat_id' => 2, 'cat_habilitado_app' => 1, 'cat_orden_app' => 2, 'cat_nombre' => 'Niña', 'cat_nombre_app' => 'Niña', 'cat_logo_app' => 'nina.png', 'cat_tallas' => '2-12', 'cat_si_sub_otras' => 1, 'cat_sub_otras' => '10,40'],
            ['cat_id' => 1, 'cat_habilitado_app' => 1, 'cat_orden_app' => 1, 'cat_nombre' => 'Niño', 'cat_nombre_app' => 'Niño', 'cat_logo_app' => 'nino.png', 'cat_tallas' => '2-14', 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
            ['cat_id' => 3, 'cat_habilitado_app' => 0, 'cat_orden_app' => 6, 'cat_nombre' => 'Bebé', 'cat_nombre_app' => 'Bebé', 'cat_logo_app' => 'bebe.png', 'cat_tallas' => '0-24M', 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
            ['cat_id' => 10, 'cat_habilitado_app' => 1, 'cat_orden_app' => 3, 'cat_nombre' => 'Diez', 'cat_nombre_app' => 'Excluida búsqueda', 'cat_logo_app' => 'diez.png', 'cat_tallas' => null, 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
            ['cat_id' => 11, 'cat_habilitado_app' => 1, 'cat_orden_app' => 4, 'cat_nombre' => 'Once', 'cat_nombre_app' => 'Categoría once', 'cat_logo_app' => 'once.png', 'cat_tallas' => null, 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
            ['cat_id' => 4, 'cat_habilitado_app' => 0, 'cat_orden_app' => 5, 'cat_nombre' => 'Inactiva', 'cat_nombre_app' => 'Inactiva', 'cat_logo_app' => 'inactiva.png', 'cat_tallas' => null, 'cat_si_sub_otras' => 0, 'cat_sub_otras' => null],
        ]);
        DB::table('stj_sub_categorias')->insert([
            ['sca_id' => 20, 'sca_categoria' => 1, 'sca_nombre' => 'Zapatos', 'sca_logo' => 'zapatos.jpg'],
            ['sca_id' => 10, 'sca_categoria' => 1, 'sca_nombre' => 'Camisas', 'sca_logo' => 'camisas.jpg'],
            ['sca_id' => 30, 'sca_categoria' => 2, 'sca_nombre' => 'Vestidos', 'sca_logo' => 'vestidos.jpg'],
            ['sca_id' => 40, 'sca_categoria' => 3, 'sca_nombre' => 'Bodies', 'sca_logo' => 'bodies.jpg'],
        ]);
        DB::table('stj_guia_tallas')->insert([
            ['gta_categoria' => 1, 'gta_orden' => 2, 'gta_talla' => '6', 'gta_peso' => '40-45', 'gta_longitud' => '24', 'gta_longitud_cm' => '61', 'gta_pecho' => null, 'gta_pecho_cm' => null, 'gta_cintura' => null, 'gta_cintura_cm' => null, 'gta_altura' => null, 'gta_altura_cm' => null, 'gta_cadera' => null, 'gta_cadera_cm' => null],
            ['gta_categoria' => 1, 'gta_orden' => 1, 'gta_talla' => '4<script>', 'gta_peso' => '35-40', 'gta_longitud' => '22', 'gta_longitud_cm' => '56', 'gta_pecho' => null, 'gta_pecho_cm' => null, 'gta_cintura' => null, 'gta_cintura_cm' => null, 'gta_altura' => null, 'gta_altura_cm' => null, 'gta_cadera' => null, 'gta_cadera_cm' => null],
        ]);

        config([
            'mobile.legacy_category_asset_url' => 'https://assets.example/categories',
            'mobile.legacy_product_image_url' => 'https://assets.example/p400',
        ]);
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

    public function test_it_returns_direct_subcategories_and_keeps_the_unused_type_segment(): void
    {
        $this->getJson('/api/mobile/v1/catalog/categories/1/subcategories/8?countryId=1')
            ->assertOk()
            ->assertJsonCount(2, 'records')
            ->assertJsonPath('records.0.categoria', 1)
            ->assertJsonPath('records.0.id', 10)
            ->assertJsonPath('records.0.nombre', 'Camisas')
            ->assertJsonPath('records.0.foto', 'https://assets.example/p400/camisas.jpg?Camisas')
            ->assertJsonPath('records.0.tallas', '2-14');
    }

    public function test_it_returns_configured_subcategories_from_other_categories(): void
    {
        $response = $this->getJson('/api/mobile/v1/catalog/categories/2/subcategories/88?countryId=1');

        $response->assertOk()
            ->assertJsonCount(2, 'records')
            ->assertJsonPath('records.0.categoria', 3)
            ->assertJsonPath('records.0.nombre', 'Bebé<br/>Bodies')
            ->assertJsonPath('records.0.tallas', '0-24M')
            ->assertJsonPath('records.1.categoria', 1)
            ->assertJsonPath('records.1.nombre', 'Niño<br/>Camisas');
    }

    public function test_subcategories_require_a_supported_category_and_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/categories/999/subcategories/8?countryId=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
        $this->getJson('/api/mobile/v1/catalog/categories/1/subcategories/8?countryId=99')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('countryId');
    }

    public function test_it_returns_the_legacy_single_category_contract(): void
    {
        $this->getJson('/api/mobile/v1/catalog/categories/1?countryId=1')
            ->assertOk()
            ->assertExactJson([
                'id' => 1,
                'nombre' => 'Niño',
                'foto' => 'https://assets.example/categories/ocho/1.jpg',
                'tipo' => 1,
            ]);
    }

    public function test_single_category_requires_a_supported_category_and_country(): void
    {
        $this->getJson('/api/mobile/v1/catalog/categories/999?countryId=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
        $this->getJson('/api/mobile/v1/catalog/categories/1?countryId=99')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('countryId');
    }

    public function test_it_returns_the_legacy_size_guide_html_in_configured_order(): void
    {
        $response = $this->getJson('/api/mobile/v1/catalog/categories/1/size-guide?countryId=1');

        $response->assertOk()
            ->assertJsonStructure(['html'])
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'Guía de tallas')
                && str_contains($html, '<ion-card-title>Pulgadas</ion-card-title>')
                && str_contains($html, '<ion-card-title>Centimetros</ion-card-title>')
                && str_contains($html, '4&lt;script&gt;')
                && strpos($html, '4&lt;script&gt;') < strpos($html, '>6<'));
    }

    public function test_size_guide_validates_country_and_category(): void
    {
        $this->getJson('/api/mobile/v1/catalog/categories/999/size-guide?countryId=1')
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->getJson('/api/mobile/v1/catalog/categories/1/size-guide?countryId=99')
            ->assertUnprocessable()->assertJsonValidationErrors('countryId');
    }
}
