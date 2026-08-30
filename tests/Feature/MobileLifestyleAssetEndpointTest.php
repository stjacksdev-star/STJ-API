<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileLifestyleAssetEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-29 12:00:00');

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
        });
        Schema::create('stj_assets', function (Blueprint $table) {
            $table->id('ast_id');
            $table->unsignedBigInteger('ast_pais');
            $table->string('ast_tipo');
            $table->string('ast_estado');
            $table->string('ast_plataforma');
            $table->string('ast_posicion')->nullable();
            $table->integer('ast_orden')->nullable();
            $table->string('ast_imagen')->nullable();
            $table->string('ast_imagen_movil')->nullable();
            $table->integer('ast_tipo_accion')->nullable();
            $table->unsignedBigInteger('ast_idpromocion')->nullable();
            $table->string('ast_titulo')->nullable();
            $table->dateTime('ast_inicio')->nullable();
            $table->dateTime('ast_fin')->nullable();
        });
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->unsignedBigInteger('prm_pais');
            $table->string('prm_estado');
            $table->string('prm_nombre');
            $table->string('prm_encabezado')->nullable();
        });
        Schema::create('stj_coleccion', function (Blueprint $table) {
            $table->id('col_id');
            $table->unsignedBigInteger('col_pais');
            $table->string('col_nombre');
            $table->string('col_header')->nullable();
        });

        DB::table('stj_paises')->insert([['pai_id' => 1], ['pai_id' => 2]]);
        config()->set('services.mobile_assets.base_url', 'https://stjacks.com');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_returns_active_app_sliders_for_the_country_in_legacy_order_and_shape(): void
    {
        DB::table('stj_assets')->insert([
            $this->asset(10, 1, 'APP', 2, '/assets/mobile-two.jpg', 7, 500, 'Coleccion'),
            $this->asset(11, 1, 'TODO', 1, 'https://cdn.example.com/mobile-one.jpg', 1, 100, 'Promocion'),
            $this->asset(12, 1, 'WEB', 0, '/web.jpg', 1, 200, 'Solo web'),
            $this->asset(13, 2, 'APP', 0, '/gt.jpg', 1, 300, 'Otro pais'),
            [...$this->asset(14, 1, 'APP', 0, '/future.jpg', 1, 400, 'Futuro'), 'ast_inicio' => '2026-08-30 00:00:00'],
        ]);

        $response = $this->getJson('/api/mobile/v1/assets/lifestyle?countryId=1&plataforma=IOS&nortizLast=1');

        $response->assertOk()->assertExactJson([
            [
                'slide' => 'https://cdn.example.com/mobile-one.jpg', 'accion' => true,
                'tipoAccion' => 1, 'promocion' => 100, 'imgHeader' => '', 'title' => 'Promocion',
                'descripcion' => '', 'categoria' => '', 'scategoria' => '', 'URL' => '',
            ],
            [
                'slide' => 'https://stjacks.com/assets/mobile-two.jpg', 'accion' => true,
                'tipoAccion' => 7, 'promocion' => 500, 'imgHeader' => '', 'title' => 'Coleccion',
                'descripcion' => '', 'categoria' => '', 'scategoria' => '', 'URL' => '',
            ],
        ]);
    }

    public function test_it_returns_active_app_banners_with_action_type_and_legacy_records_wrapper(): void
    {
        DB::table('stj_assets')->insert([
            [...$this->asset(20, 1, 'APP', 2, '/banner-two.jpg', 6, 0, 'Jack and Co'), 'ast_tipo' => 'BANNER'],
            [...$this->asset(21, 1, 'TODO', 1, 'https://cdn.example.com/banner-one.jpg', 1, 700, 'Promocion'), 'ast_tipo' => 'BANNER'],
            [...$this->asset(22, 1, 'WEB', 0, '/web-banner.jpg', 1, 800, 'Solo web'), 'ast_tipo' => 'BANNER'],
        ]);

        $response = $this->getJson('/api/mobile/v1/assets/banners?countryId=1&plataforma=IOS');

        $response->assertOk()->assertExactJson([
            'records' => [
                [
                    'banner' => 'https://cdn.example.com/banner-one.jpg', 'accion' => true,
                    'tipoAccion' => 1, 'imgHeader' => 'https://cdn.example.com/banner-one.jpg',
                    'title' => 'Promocion', 'descripcion' => '', 'promocion' => 700,
                    'categoria' => null, 'scategoria' => null, 'URL' => null,
                ],
                [
                    'banner' => 'https://stjacks.com/banner-two.jpg', 'accion' => true,
                    'tipoAccion' => 6, 'imgHeader' => 'https://stjacks.com/banner-two.jpg',
                    'title' => 'Jack and Co', 'descripcion' => '', 'promocion' => 0,
                    'categoria' => null, 'scategoria' => null, 'URL' => null,
                ],
            ],
        ]);
    }

    public function test_it_combines_left_center_and_right_new_arrivals_in_one_endpoint(): void
    {
        DB::table('stj_promociones')->insert([
            ['prm_id' => 700, 'prm_pais' => 1, 'prm_estado' => 'EN-PROCESO', 'prm_nombre' => 'Promocion activa', 'prm_encabezado' => '/promo-header.jpg'],
            ['prm_id' => 701, 'prm_pais' => 1, 'prm_estado' => 'FINALIZADO', 'prm_nombre' => 'Promocion finalizada', 'prm_encabezado' => null],
        ]);
        DB::table('stj_coleccion')->insert(['col_id' => 900, 'col_pais' => 1, 'col_nombre' => 'Spider Man', 'col_header' => '/collection-header.jpg']);
        DB::table('stj_assets')->insert([
            [...$this->asset(30, 1, 'APP', 1, '/left.jpg', 1, 700, 'Fallback'), 'ast_tipo' => 'LO-MAS-NUEVO', 'ast_posicion' => 'IZQUIERDA'],
            [...$this->asset(31, 1, 'TODO', 1, '/center.jpg', 7, 900, 'Fallback'), 'ast_tipo' => 'LO-MAS-NUEVO', 'ast_posicion' => 'CENTRO'],
            [...$this->asset(32, 1, 'APP', 1, '/right.jpg', 7, 900, 'Fallback'), 'ast_tipo' => 'LO-MAS-NUEVO', 'ast_posicion' => 'DERECHA'],
            [...$this->asset(33, 1, 'APP', 2, '/finished.jpg', 1, 701, 'No mostrar'), 'ast_tipo' => 'LO-MAS-NUEVO', 'ast_posicion' => 'IZQUIERDA'],
        ]);

        $response = $this->getJson('/api/mobile/v1/assets/new-arrivals?countryId=1&plataforma=IOS');

        $response->assertOk()
            ->assertJsonCount(1, 'left')
            ->assertJsonCount(1, 'center')
            ->assertJsonCount(1, 'right')
            ->assertJsonPath('left.0.tipoAccion', 1)
            ->assertJsonPath('left.0.title', 'Promocion activa')
            ->assertJsonPath('center.0.tipoAccion', 7)
            ->assertJsonPath('center.0.title', 'Spider Man')
            ->assertJsonPath('right.0.promocion', 900);
    }

    /** @return array<string, mixed> */
    private function asset(int $id, int $country, string $platform, int $order, string $image, int $action, int $promotion, string $title): array
    {
        return [
            'ast_id' => $id, 'ast_pais' => $country, 'ast_tipo' => 'SLIDER', 'ast_estado' => 'ACTIVO',
            'ast_plataforma' => $platform, 'ast_orden' => $order, 'ast_imagen' => '/desktop.jpg',
            'ast_imagen_movil' => $image, 'ast_tipo_accion' => $action, 'ast_idpromocion' => $promotion,
            'ast_titulo' => $title, 'ast_posicion' => null,
            'ast_inicio' => '2026-08-01 00:00:00', 'ast_fin' => '2026-08-31 23:59:59',
        ];
    }
}
