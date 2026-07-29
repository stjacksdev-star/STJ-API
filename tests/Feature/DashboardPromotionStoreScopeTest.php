<?php

namespace Tests\Feature;

use App\Services\Dashboard\PromotionHistoryService;
use App\Services\Dashboard\PromotionProductImportService;
use App\Services\Dashboard\PromotionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardPromotionStoreScopeTest extends TestCase
{
    private PromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->createSchema();
        $this->seedPromotion();

        $this->service = new PromotionService(
            new PromotionProductImportService,
            new PromotionHistoryService,
        );
    }

    public function test_selected_stores_are_saved_and_returned(): void
    {
        $promotion = $this->service->updateStores(10, 'SELECCIONADAS', [2, 1]);

        $this->assertSame('SELECCIONADAS', $promotion['storeScope']);
        $this->assertSame([1, 2], collect($promotion['tiendas'])->pluck('id')->sort()->values()->all());
        $this->assertDatabaseCount('stj_promociones_tienda', 2);
    }

    public function test_todas_and_null_remove_existing_relations(): void
    {
        $this->service->updateStores(10, 'SELECCIONADAS', [1]);

        $promotion = $this->service->updateStores(10, 'TODAS', [2]);
        $this->assertSame('TODAS', $promotion['storeScope']);
        $this->assertSame([], $promotion['tiendas']);
        $this->assertDatabaseCount('stj_promociones_tienda', 0);

        $promotion = $this->service->updateStores(10, null, []);
        $this->assertNull($promotion['storeScope']);
        $this->assertDatabaseCount('stj_promociones_tienda', 0);
    }

    public function test_eligible_stores_are_active_for_selected_country_and_keep_text_code(): void
    {
        $stores = $this->service->eligibleStores('SV');

        $this->assertSame([1, 2], collect($stores)->pluck('id')->sort()->values()->all());
        $this->assertSame('001', collect($stores)->firstWhere('id', 1)['code']);
        $this->assertNotContains(3, collect($stores)->pluck('id'));
        $this->assertNotContains(4, collect($stores)->pluck('id'));
    }

    public function test_stores_cannot_be_modified_after_pending_status(): void
    {
        DB::table('stj_promociones')->where('prm_id', 10)->update(['prm_estado' => 'EN-PROCESO']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Las tiendas solo pueden modificarse en promociones PENDIENTE.');

        $this->service->updateStores(10, 'SELECCIONADAS', [1]);
    }

    #[DataProvider('invalidStores')]
    public function test_invalid_selected_stores_roll_back(array $storeIds, string $message): void
    {
        DB::table('stj_promociones_tienda')->insert([
            'prt_promocion' => 10,
            'prt_tienda' => 1,
            'prt_fecha_creacion' => now(),
        ]);

        try {
            $this->service->updateStores(10, 'SELECCIONADAS', $storeIds);
            $this->fail('Se esperaba una excepcion de validacion.');
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()['stores'][0]);
        }

        $this->assertDatabaseHas('stj_promociones', [
            'prm_id' => 10,
            'prm_alcance_tienda' => null,
        ]);
        $this->assertDatabaseHas('stj_promociones_tienda', [
            'prt_promocion' => 10,
            'prt_tienda' => 1,
        ]);
    }

    public static function invalidStores(): array
    {
        return [
            'empty' => [[], 'Debe seleccionar al menos una tienda.'],
            'duplicate' => [[1, 1], 'No se permiten tiendas duplicadas.'],
            'missing' => [[999], 'Una o mas tiendas seleccionadas no existen.'],
            'other country' => [[3], 'Todas las tiendas deben pertenecer al pais de la promocion.'],
            'products disabled' => [[4], 'Todas las tiendas deben tener productos habilitados.'],
        ];
    }

    private function seedPromotion(): void
    {
        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador'],
            ['pai_id' => 2, 'pai_codigo' => 'GT', 'pai_nombre' => 'Guatemala'],
        ]);

        DB::table('stj_tiendas')->insert([
            ['tie_id' => 1, 'tie_codigo' => '001', 'tie_nombre' => 'Centro', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_id' => 2, 'tie_codigo' => '002', 'tie_nombre' => 'Norte', 'tie_pais' => 1, 'tie_productos' => 1],
            ['tie_id' => 3, 'tie_codigo' => '003', 'tie_nombre' => 'Guatemala', 'tie_pais' => 2, 'tie_productos' => 1],
            ['tie_id' => 4, 'tie_codigo' => '004', 'tie_nombre' => 'Sin productos', 'tie_pais' => 1, 'tie_productos' => 0],
        ]);

        DB::table('stj_promociones')->insert([
            'prm_id' => 10,
            'prm_pais' => 1,
            'prm_nombre' => 'Promocion',
            'prm_nombre_comercial' => 'Promocion',
            'prm_tipo_checkout' => 'TODO',
            'prm_alcance_tienda' => null,
            'prm_modalidad' => 'PROGRAMADO',
            'prm_tipo' => 'TODO',
            'prm_estado' => 'PENDIENTE',
            'prm_tipo_promocion' => 'DESCUENTO',
            'prm_aplica' => 'TODO',
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });

        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->id('tie_id');
            $table->string('tie_codigo')->nullable();
            $table->string('tie_nombre')->nullable();
            $table->unsignedBigInteger('tie_pais');
            $table->boolean('tie_productos');
        });

        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->string('prm_ticket')->nullable();
            $table->unsignedBigInteger('prm_pais');
            $table->string('prm_origen')->nullable();
            $table->string('prm_nombre');
            $table->string('prm_nombre_comercial')->nullable();
            $table->string('prm_tipo_checkout')->nullable();
            $table->string('prm_alcance_tienda')->nullable();
            $table->string('prm_modalidad')->nullable();
            $table->string('prm_tipo')->nullable();
            $table->string('prm_estado')->nullable();
            $table->string('prm_tipo_promocion')->nullable();
            $table->string('prm_aplica')->nullable();
            $table->decimal('prm_precio')->nullable();
            $table->decimal('prm_porcentaje')->nullable();
            $table->string('prm_restriccion')->nullable();
            $table->dateTime('prm_fecha')->nullable();
            $table->string('prm_grid_promo')->nullable();
            $table->string('prm_encabezado')->nullable();
        });

        Schema::create('stj_promociones_tienda', function (Blueprint $table) {
            $table->id('prt_id');
            $table->unsignedBigInteger('prt_promocion');
            $table->unsignedBigInteger('prt_tienda');
            $table->dateTime('prt_fecha_creacion')->nullable();
            $table->unique(['prt_promocion', 'prt_tienda']);
        });

        Schema::create('stj_promociones_horario', function (Blueprint $table) {
            $table->id('pho_id');
            $table->unsignedBigInteger('pho_promocion');
            $table->string('pho_tipo');
            $table->dateTime('pho_inicio')->nullable();
            $table->dateTime('pho_fin')->nullable();
            $table->string('pho_estado')->nullable();
        });

        Schema::create('stj_promociones_producto', function (Blueprint $table) {
            $table->unsignedBigInteger('ppr_promocion');
        });

        Schema::create('stj_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('ast_idpromocion');
            $table->integer('ast_tipo_accion');
        });

        Schema::create('stj_promociones_historial', function (Blueprint $table) {
            $table->id('pph_id');
            $table->unsignedBigInteger('pph_promocion');
            $table->string('pph_usuario_id')->nullable();
            $table->string('pph_usuario_nombre')->nullable();
            $table->string('pph_accion');
            $table->string('pph_descripcion');
            $table->dateTime('pph_fecha');
        });
    }
}
