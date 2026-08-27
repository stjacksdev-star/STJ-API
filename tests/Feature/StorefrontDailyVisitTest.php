<?php

namespace Tests\Feature;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontDailyVisitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontDailyVisitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('stj_paises')) {
            Schema::create('stj_paises', function (Blueprint $table) {
                $table->bigInteger('pai_id', true);
                $table->string('pai_codigo', 3)->unique();
            });
        }
        if (! Schema::hasTable('stj_usuarios')) {
            Schema::create('stj_usuarios', function (Blueprint $table) {
                $table->bigInteger('usu_id', true);
            });
        }
        if (! Schema::hasTable('stj_visitas_diarias')) {
            Schema::create('stj_visitas_diarias', function (Blueprint $table) {
                $table->bigInteger('vdi_id', true);
                $table->date('vdi_fecha');
                $table->bigInteger('vdi_visitante_id');
                $table->bigInteger('vdi_usuario_id')->nullable();
                $table->bigInteger('vdi_pais_id');
                $table->string('vdi_origen', 20);
                $table->dateTime('vdi_primera_hora');
                $table->dateTime('vdi_ultima_hora');
                $table->dateTime('vdi_creado_en');
                $table->dateTime('vdi_actualizado_en');
                $table->unique(
                    ['vdi_fecha', 'vdi_visitante_id', 'vdi_pais_id', 'vdi_origen'],
                    'uq_vdi_dia_visitante_pais_origen',
                );
            });
        }

        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV'],
            ['pai_id' => 2, 'pai_codigo' => 'GT'],
        ]);
    }

    public function test_same_browser_country_and_day_is_counted_once(): void
    {
        $first = $this->postJson('/api/storefront/visits/sv');
        $first->assertCreated()->assertJsonPath('data.created', true);

        $visitor = StorefrontVisitor::query()->firstOrFail();
        $result = app(StorefrontDailyVisitService::class)->record('sv', $visitor);

        $this->assertFalse($result['created']);
        $this->assertDatabaseCount('stj_visitantes', 1);
        $this->assertDatabaseCount('stj_visitas_diarias', 1);
    }

    public function test_same_browser_can_record_each_country_and_each_day(): void
    {
        $visitor = $this->visitor();
        $visits = app(StorefrontDailyVisitService::class);

        $this->assertTrue($visits->record('sv', $visitor)['created']);
        $this->assertTrue($visits->record('gt', $visitor)['created']);

        $this->travel(1)->day();
        $this->assertTrue($visits->record('sv', $visitor)['created']);

        $this->assertDatabaseCount('stj_visitantes', 1);
        $this->assertDatabaseCount('stj_visitas_diarias', 3);
    }

    public function test_login_associates_customer_without_creating_another_visit(): void
    {
        $visitor = $this->visitor();
        $visits = app(StorefrontDailyVisitService::class);
        $this->assertTrue($visits->record('sv', $visitor)['created']);

        DB::table('stj_usuarios')->insert(['usu_id' => 77]);
        $customer = StorefrontCustomer::query()->findOrFail(77);
        Sanctum::actingAs($customer, ['storefront:account']);
        $this->assertFalse($visits->record('sv', $visitor, $customer)['created']);

        $this->assertDatabaseCount('stj_visitas_diarias', 1);
        $this->assertDatabaseHas('stj_visitas_diarias', [
            'vdi_usuario_id' => 77,
            'vdi_pais_id' => 1,
            'vdi_origen' => 'WEB',
        ]);
    }

    public function test_unknown_country_is_rejected_without_recording_visit(): void
    {
        $this->postJson('/api/storefront/visits/xx')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country');

        $this->assertDatabaseCount('stj_visitas_diarias', 0);
    }

    private function visitor(): StorefrontVisitor
    {
        $now = now();

        return StorefrontVisitor::query()->create([
            'vis_uuid' => (string) Str::uuid(),
            'vis_origen' => 'WEB',
            'vis_pais_id' => 1,
            'vis_primera_visita' => $now,
            'vis_ultima_visita' => $now,
            'vis_expira_en' => $now->copy()->addYear(),
            'vis_creado_en' => $now,
            'vis_actualizado_en' => $now,
        ]);
    }
}
