<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveStorefrontVisitor;
use App\Services\ProductListAvailabilityService;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontNavigationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class StorefrontNavigationTest extends TestCase
{
    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ResolveStorefrontVisitor::class);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->snapshot = storage_path('app/storefront/navigation/zz.json');
        File::delete($this->snapshot);
        Cache::flush();
        $this->createSchema();
        $this->seedData();
    }

    protected function tearDown(): void
    {
        File::delete($this->snapshot);
        parent::tearDown();
    }

    public function test_it_builds_a_versioned_country_snapshot_from_active_products(): void
    {
        $payload = app(StorefrontNavigationService::class)->build('zz');

        $this->assertSame(1, $payload['version']);
        $this->assertSame('zz', $payload['country']);
        $this->assertFileExists($this->snapshot);
        $this->assertSame(['girls', 'accessories'], array_column($payload['groups'], 'key'));
        $this->assertSame(2, $payload['groups'][0]['productCount']);
        $this->assertSame('Vestidos', $payload['groups'][0]['segments'][0]['subcategories'][0]['label']);
        $this->assertTrue($payload['groups'][0]['segments'][0]['subcategories'][0]['isNew']);
    }

    public function test_endpoint_returns_cache_headers_and_honors_etag(): void
    {
        app(StorefrontNavigationService::class)->build('zz');

        $response = $this->getJson('/api/storefront/navigation/zz')
            ->assertOk()
            ->assertJsonPath('data.groups.0.key', 'girls')
            ->assertHeader('Cache-Control');

        $etag = $response->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->get('/api/storefront/navigation/zz')->assertStatus(304);
    }

    public function test_catalog_can_filter_by_stable_subcategory_id(): void
    {
        DB::table('stj_productos')->insert(['pro_id' => 4, 'pro_codigo' => 'P-4', 'pro_nombre' => 'Pijama', 'pro_categoria' => 5, 'pro_sub_categoria' => 20, 'pro_estatus' => 'ACTIVO']);
        DB::table('stj_producto_pais')->insert(['ppa_id' => 4, 'ppa_pais' => 99, 'ppa_producto' => 4, 'ppa_estado' => 'ACTIVO', 'ppa_fecha_activo' => now(), 'ppa_precio' => 20]);

        $availability = Mockery::mock(ProductListAvailabilityService::class);
        $availability->shouldReceive('summarize')->once()->andReturn(['availabilityBySku' => [], 'activeStoreCode' => null, 'usedSource' => 'test']);

        $result = (new StorefrontCatalogService($availability))->forCountry('zz', null, ['group' => 'girls', 'subcategory' => 10]);

        $this->assertSame([1, 2], array_column($result['products'], 'id'));
        $this->assertSame(10, $result['filters']['active']['subcategory']);
    }

    private function createSchema(): void
    {
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });
        Schema::create('stj_categorias', function (Blueprint $table) {
            $table->id('cat_id');
            $table->string('cat_codigo')->nullable();
            $table->string('cat_nombre');
            $table->string('cat_header')->nullable();
            $table->text('cat_descripcion')->nullable();
            $table->integer('cat_orden')->nullable();
            $table->boolean('cat_si_sub_otras')->default(false);
            $table->text('cat_sub_otras')->nullable();
        });
        Schema::create('stj_sub_categorias', function (Blueprint $table) {
            $table->id('sca_id');
            $table->string('sca_nombre');
        });
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->id('pro_id');
            $table->unsignedBigInteger('pro_categoria');
            $table->unsignedBigInteger('pro_sub_categoria');
            $table->string('pro_estatus');
            $table->string('pro_codigo')->nullable();
            $table->string('pro_nombre')->nullable();
            $table->text('pro_descripcion')->nullable();
            $table->string('pro_marca')->nullable();
            $table->string('pro_tags')->nullable();
            $table->string('pro_oc_categoria')->nullable();
            $table->string('pro_tallas')->nullable();
            $table->string('pro_thumbs')->nullable();
            $table->string('pro_denim_fit')->nullable();
            $table->dateTime('pro_registro')->nullable();
        });
        Schema::create('stj_producto_pais', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pais');
            $table->unsignedBigInteger('ppa_producto');
            $table->string('ppa_estado');
            $table->dateTime('ppa_fecha_activo')->nullable();
            $table->decimal('ppa_precio')->default(10);
            $table->boolean('ppa_es_popular')->default(false);
        });
    }

    private function seedData(): void
    {
        DB::table('stj_paises')->insert(['pai_id' => 99, 'pai_codigo' => 'ZZ', 'pai_nombre' => 'Pruebas']);
        DB::table('stj_categorias')->insert([
            ['cat_id' => 5, 'cat_codigo' => 'Ninas', 'cat_nombre' => 'Niñas'],
            ['cat_id' => 30, 'cat_codigo' => 'Otros', 'cat_nombre' => 'Otros'],
        ]);
        DB::table('stj_sub_categorias')->insert([
            ['sca_id' => 10, 'sca_nombre' => 'Vestidos'],
            ['sca_id' => 20, 'sca_nombre' => 'Pijamas'],
            ['sca_id' => 30, 'sca_nombre' => 'Mochilas'],
        ]);
        DB::table('stj_productos')->insert([
            ['pro_id' => 1, 'pro_codigo' => 'P-1', 'pro_nombre' => 'Vestido uno', 'pro_categoria' => 5, 'pro_sub_categoria' => 10, 'pro_estatus' => 'ACTIVO'],
            ['pro_id' => 2, 'pro_codigo' => 'P-2', 'pro_nombre' => 'Vestido dos', 'pro_categoria' => 5, 'pro_sub_categoria' => 10, 'pro_estatus' => 'ACTIVO'],
            ['pro_id' => 3, 'pro_codigo' => 'P-3', 'pro_nombre' => 'Pijama inactivo', 'pro_categoria' => 5, 'pro_sub_categoria' => 20, 'pro_estatus' => 'INACTIVO'],
            ['pro_id' => 5, 'pro_codigo' => 'P-5', 'pro_nombre' => 'Mochila', 'pro_categoria' => 30, 'pro_sub_categoria' => 30, 'pro_estatus' => 'ACTIVO'],
        ]);
        DB::table('stj_producto_pais')->insert([
            ['ppa_id' => 1, 'ppa_pais' => 99, 'ppa_producto' => 1, 'ppa_estado' => 'ACTIVO', 'ppa_fecha_activo' => now()->subDay()],
            ['ppa_id' => 2, 'ppa_pais' => 99, 'ppa_producto' => 2, 'ppa_estado' => 'ACTIVO', 'ppa_fecha_activo' => now()->subDays(45)],
            ['ppa_id' => 3, 'ppa_pais' => 99, 'ppa_producto' => 3, 'ppa_estado' => 'ACTIVO', 'ppa_fecha_activo' => now()],
            ['ppa_id' => 5, 'ppa_pais' => 99, 'ppa_producto' => 5, 'ppa_estado' => 'ACTIVO', 'ppa_fecha_activo' => now()],
        ]);
    }
}
