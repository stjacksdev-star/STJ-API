<?php

namespace Tests\Feature;

use App\Models\StorefrontCustomer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileDailyVisitEndpointTest extends TestCase
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

    public function test_anonymous_installation_is_counted_once_per_day_country_and_platform(): void
    {
        $payload = $this->payload();

        $this->postJson('/api/mobile/v1/visits', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.origin', 'APP-IOS');
        $this->postJson('/api/mobile/v1/visits', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertDatabaseCount('stj_visitantes', 1);
        $this->assertDatabaseCount('stj_visitas_diarias', 1);
        $this->assertDatabaseHas('stj_visitas_diarias', [
            'vdi_usuario_id' => null,
            'vdi_pais_id' => 1,
            'vdi_origen' => 'APP-IOS',
        ]);
    }

    public function test_same_installation_can_record_another_country_and_day(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/mobile/v1/visits', $payload)->assertCreated();
        $this->postJson('/api/mobile/v1/visits', [...$payload, 'countryId' => 2])->assertCreated();

        $this->travel(1)->day();
        $this->postJson('/api/mobile/v1/visits', $payload)->assertCreated();

        $this->assertDatabaseCount('stj_visitantes', 1);
        $this->assertDatabaseCount('stj_visitas_diarias', 3);
    }

    public function test_mobile_customer_is_associated_without_duplicating_visit(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/mobile/v1/visits', $payload)->assertCreated();

        DB::table('stj_usuarios')->insert(['usu_id' => 77]);
        Sanctum::actingAs(StorefrontCustomer::query()->findOrFail(77), ['mobile:account']);

        $this->postJson('/api/mobile/v1/visits', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertDatabaseCount('stj_visitas_diarias', 1);
        $this->assertDatabaseHas('stj_visitas_diarias', ['vdi_usuario_id' => 77]);
    }

    public function test_technical_token_is_never_attributed_as_customer(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/mobile/v1/visits', $this->payload())->assertCreated();

        $this->assertDatabaseHas('stj_visitas_diarias', ['vdi_usuario_id' => null]);
    }

    public function test_payload_country_and_platform_are_validated(): void
    {
        $this->postJson('/api/mobile/v1/visits', [
            'installation_uuid' => 'not-a-uuid',
            'countryId' => 999,
            'platform' => 'WEB',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['installation_uuid', 'platform']);

        $this->postJson('/api/mobile/v1/visits', [
            ...$this->payload(),
            'countryId' => 999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('countryId');

        $this->assertDatabaseCount('stj_visitas_diarias', 0);
    }

    private function payload(): array
    {
        return [
            'installation_uuid' => (string) Str::uuid(),
            'countryId' => 1,
            'platform' => 'IOS',
        ];
    }
}
