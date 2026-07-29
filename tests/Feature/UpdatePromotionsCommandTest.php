<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpdatePromotionsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        config()->set('promotions.timezone', 'America/El_Salvador');
        $this->createSchema();
    }

    public function test_legacy_authority_reports_candidates_without_writing(): void
    {
        $this->seedPendingPromotion();
        config()->set('promotions.lifecycle_authority', 'legacy');

        $this->artisan('promotions:update', ['--at' => '2026-07-28 12:00:00'])
            ->expectsOutputToContain('Autoridad: legacy')
            ->expectsOutputToContain('Escrituras habilitadas: NO')
            ->expectsOutputToContain('Candidatos activation: 1')
            ->assertSuccessful();

        $this->assertPending();
    }

    public function test_disabled_authority_does_not_write(): void
    {
        $this->seedPendingPromotion();
        config()->set('promotions.lifecycle_authority', 'disabled');

        $this->artisan('promotions:update', ['--at' => '2026-07-28 12:00:00'])
            ->expectsOutputToContain('Autoridad: disabled')
            ->expectsOutputToContain('Escrituras habilitadas: NO')
            ->assertSuccessful();

        $this->assertPending();
    }

    public function test_stj_api_authority_writes_transitions(): void
    {
        $this->seedPendingPromotion();
        config()->set('promotions.lifecycle_authority', 'stj-api');

        $this->artisan('promotions:update', ['--at' => '2026-07-28 12:00:00'])
            ->expectsOutputToContain('Autoridad: stj-api')
            ->expectsOutputToContain('Escrituras habilitadas: SI')
            ->expectsOutputToContain('Transiciones: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 1, 'prm_estado' => 'EN-PROCESO']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 1, 'pho_estado' => 'ACTIVO']);
    }

    public function test_dry_run_never_writes_with_stj_api_authority(): void
    {
        $this->seedPendingPromotion();
        config()->set('promotions.lifecycle_authority', 'stj-api');

        $this->artisan('promotions:update', [
            '--dry-run' => true,
            '--at' => '2026-07-28 12:00:00',
        ])
            ->expectsOutputToContain('Escrituras habilitadas: NO')
            ->expectsOutputToContain('Transiciones: 1')
            ->assertSuccessful();

        $this->assertPending();
    }

    public function test_invalid_authority_fails_without_writing(): void
    {
        $this->seedPendingPromotion();
        config()->set('promotions.lifecycle_authority', 'unknown');

        $this->artisan('promotions:update', ['--at' => '2026-07-28 12:00:00'])
            ->expectsOutputToContain('Autoridad de promociones no valida')
            ->assertFailed();

        $this->assertPending();
    }

    public function test_store_scope_migration_is_idempotent_and_non_destructive(): void
    {
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->id('tie_id');
        });

        $migration = require database_path('migrations/2026_07_28_000001_add_promotion_store_scope_schema.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('stj_promociones', 'prm_alcance_tienda'));
        $this->assertTrue(Schema::hasTable('stj_promociones_tienda'));
        $this->assertTrue(Schema::hasIndex('stj_promociones_tienda', 'uk_promocion_tienda'));
        $this->assertTrue(Schema::hasIndex('stj_promociones_tienda', 'idx_prt_tienda'));

        $migration->down();

        $this->assertTrue(Schema::hasColumn('stj_promociones', 'prm_alcance_tienda'));
        $this->assertTrue(Schema::hasTable('stj_promociones_tienda'));
    }

    private function createSchema(): void
    {
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->string('prm_estado');
        });
        Schema::create('stj_promociones_horario', function (Blueprint $table) {
            $table->id('pho_id');
            $table->string('pho_tipo');
            $table->unsignedBigInteger('pho_promocion');
            $table->dateTime('pho_inicio')->nullable();
            $table->dateTime('pho_fin')->nullable();
            $table->string('pho_estado');
        });
    }

    private function seedPendingPromotion(): void
    {
        DB::table('stj_promociones')->insert([
            'prm_id' => 1,
            'prm_estado' => 'PENDIENTE',
        ]);
        DB::table('stj_promociones_horario')->insert([
            'pho_id' => 1,
            'pho_tipo' => 'NORMAL',
            'pho_promocion' => 1,
            'pho_inicio' => '2026-07-28 11:00:00',
            'pho_fin' => '2026-07-28 13:00:00',
            'pho_estado' => 'PENDIENTE',
        ]);
    }

    private function assertPending(): void
    {
        $this->assertDatabaseHas('stj_promociones', ['prm_id' => 1, 'prm_estado' => 'PENDIENTE']);
        $this->assertDatabaseHas('stj_promociones_horario', ['pho_id' => 1, 'pho_estado' => 'PENDIENTE']);
    }
}
