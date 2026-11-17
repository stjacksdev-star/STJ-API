<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CitasStoreEndpointTest extends TestCase
{
    private string $token = 'stj_citas_test_token_with_enough_entropy';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_api_clientes', function (Blueprint $table) {
            $table->id('apc_id');
            $table->string('apc_nombre');
            $table->string('apc_codigo', 50)->unique();
            $table->string('apc_token_prefijo', 20);
            $table->string('apc_token_hash', 64)->unique();
            $table->string('apc_permiso');
            $table->unsignedBigInteger('apc_pais_id')->default(1);
            $table->string('apc_estado');
            $table->string('apc_dominio_referencia')->nullable();
            $table->dateTime('apc_expira_en')->nullable();
            $table->dateTime('apc_ultimo_uso')->nullable();
            $table->unsignedBigInteger('apc_creado_por')->nullable();
            $table->dateTime('apc_creado_en')->nullable();
            $table->dateTime('apc_actualizado_en')->nullable();
            $table->dateTime('apc_revocado_en')->nullable();
        });
        Schema::create('stj_api_consultas', function (Blueprint $table) {
            $table->id('aqc_id');
            $table->uuid('aqc_uuid');
            $table->unsignedBigInteger('aqc_cliente_id')->nullable();
            $table->string('aqc_endpoint');
            $table->string('aqc_metodo');
            $table->string('aqc_referencia')->nullable();
            $table->unsignedBigInteger('aqc_pais_id')->nullable();
            $table->string('aqc_ip')->nullable();
            $table->string('aqc_user_agent', 500)->nullable();
            $table->unsignedSmallInteger('aqc_http_estado');
            $table->string('aqc_resultado');
            $table->unsignedInteger('aqc_duracion_ms')->nullable();
            $table->string('aqc_mensaje', 500)->nullable();
            $table->dateTime('aqc_creado_en');
        });
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->id('tie_id');
            $table->unsignedBigInteger('tie_pais');
            $table->string('tie_nombre');
            $table->string('tie_direccion')->nullable();
            $table->string('tie_telefono')->nullable();
            $table->string('tie_correo')->nullable();
            $table->unsignedTinyInteger('tie_productos');
        });

        DB::table('stj_api_clientes')->insert([
            'apc_id' => 1,
            'apc_nombre' => 'STJ Citas',
            'apc_codigo' => 'stj_citas',
            'apc_token_prefijo' => substr($this->token, 0, 20),
            'apc_token_hash' => hash('sha256', $this->token),
            'apc_permiso' => 'citas.stores.read',
            'apc_pais_id' => 1,
            'apc_estado' => 'ACTIVO',
        ]);
        DB::table('stj_tiendas')->insert([
            ['tie_id' => 10, 'tie_pais' => 2, 'tie_nombre' => 'Zona 10', 'tie_direccion' => 'Dirección GT', 'tie_telefono' => '2222-2222', 'tie_correo' => 'gt@example.test', 'tie_productos' => 1],
            ['tie_id' => 11, 'tie_pais' => 1, 'tie_nombre' => 'Ahuachapán', 'tie_direccion' => 'Dirección SV', 'tie_telefono' => '1111-1111', 'tie_correo' => 'sv@example.test', 'tie_productos' => 1],
            ['tie_id' => 12, 'tie_pais' => 7, 'tie_nombre' => 'Tegucigalpa', 'tie_direccion' => 'Dirección HN', 'tie_telefono' => '3333-3333', 'tie_correo' => 'hn@example.test', 'tie_productos' => 0],
            ['tie_id' => 13, 'tie_pais' => 4, 'tie_nombre' => 'País no permitido', 'tie_direccion' => null, 'tie_telefono' => null, 'tie_correo' => null, 'tie_productos' => 1],
        ]);
    }

    public function test_authorized_citas_client_receives_only_eligible_stores(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/integraciones/citas/tiendas')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.tie_id', 11)
            ->assertJsonPath('data.0.tie_pais', 1)
            ->assertJsonPath('data.0.tie_nombre', 'Ahuachapán')
            ->assertJsonPath('data.1.tie_id', 10)
            ->assertJsonPath('data.1.tie_pais', 2)
            ->assertJsonPath('data.1.tie_productos', 1);

        $this->assertNotNull(DB::table('stj_api_clientes')->where('apc_id', 1)->value('apc_ultimo_uso'));
        $this->assertDatabaseHas('stj_api_consultas', [
            'aqc_cliente_id' => 1,
            'aqc_endpoint' => '/api/integraciones/citas/tiendas',
            'aqc_http_estado' => 200,
            'aqc_resultado' => 'EXITOSO',
        ]);
    }

    public function test_endpoint_rejects_missing_wrong_permission_expired_and_revoked_tokens(): void
    {
        $this->getJson('/api/integraciones/citas/tiendas')->assertUnauthorized();

        DB::table('stj_api_clientes')->where('apc_id', 1)->update(['apc_permiso' => 'sv.billing.orders.read']);
        $this->withToken($this->token)->getJson('/api/integraciones/citas/tiendas')->assertUnauthorized();

        DB::table('stj_api_clientes')->where('apc_id', 1)->update([
            'apc_permiso' => 'citas.stores.read',
            'apc_expira_en' => now()->subMinute(),
        ]);
        $this->withToken($this->token)->getJson('/api/integraciones/citas/tiendas')->assertUnauthorized();

        DB::table('stj_api_clientes')->where('apc_id', 1)->update([
            'apc_expira_en' => null,
            'apc_estado' => 'REVOCADO',
        ]);
        $this->withToken($this->token)->getJson('/api/integraciones/citas/tiendas')->assertUnauthorized();
    }

    public function test_issue_command_creates_an_exclusive_hashed_client_token(): void
    {
        DB::table('stj_api_clientes')->delete();

        $this->artisan('citas:issue-token --days=30')
            ->expectsOutputToContain('Permiso: citas.stores.read')
            ->assertSuccessful();

        $client = DB::table('stj_api_clientes')->where('apc_codigo', 'stj_citas')->first();
        $this->assertNotNull($client);
        $this->assertSame('ACTIVO', $client->apc_estado);
        $this->assertSame('citas.stores.read', $client->apc_permiso);
        $this->assertSame('http://stj-citas.test/', $client->apc_dominio_referencia);
        $this->assertSame(64, strlen($client->apc_token_hash));
        $this->assertStringStartsWith('stj_citas_', $client->apc_token_prefijo);
    }
}
