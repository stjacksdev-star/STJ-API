<?php

namespace Tests\Feature;

use App\Models\StorefrontCustomer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileAddressEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_usuarios', function (Blueprint $table) {
            $table->id('usu_id');
            $table->string('usu_usuario');
            $table->string('usu_password');
        });
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->bigInteger('pai_id')->primary();
            $table->string('pai_codigo');
        });
        Schema::create('stj_world_countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('iso2', 2);
        });
        Schema::create('stj_world_states', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('country_id');
            $table->string('name');
        });
        Schema::create('stj_world_cities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('state_id');
            $table->string('name');
        });
        Schema::create('stj_direcciones', function (Blueprint $table) {
            $table->id('dir_id');
            $table->timestamp('dir_fecha')->nullable();
            $table->bigInteger('dir_usuario');
            foreach (['dir_tipo', 'dir_misma_persona', 'dir_misma_direccion', 'dir_pais', 'dir_direccion', 'dir_referencia', 'dir_departamento', 'dir_municipio', 'dir_departamento_txt', 'dir_municipio_txt', 'dir_persona', 'dir_telefono', 'dir_save', 'dir_principal'] as $column) {
                $table->string($column)->nullable();
            }
        });

        DB::table('stj_usuarios')->insert([
            ['usu_id' => 77, 'usu_usuario' => 'cliente@example.com', 'usu_password' => 'x'],
            ['usu_id' => 88, 'usu_usuario' => 'otro@example.com', 'usu_password' => 'x'],
        ]);
        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV'],
            ['pai_id' => 2, 'pai_codigo' => 'GT'],
        ]);
        DB::table('stj_world_countries')->insert([
            ['id' => 1, 'name' => 'El Salvador', 'iso2' => 'SV'],
            ['id' => 2, 'name' => 'Guatemala', 'iso2' => 'GT'],
        ]);
        DB::table('stj_world_states')->insert([
            ['id' => 10, 'country_id' => 1, 'name' => 'San Salvador'],
            ['id' => 20, 'country_id' => 2, 'name' => 'Guatemala'],
        ]);
        DB::table('stj_world_cities')->insert([
            ['id' => 100, 'state_id' => 10, 'name' => 'San Salvador Centro'],
            ['id' => 200, 'state_id' => 20, 'name' => 'Ciudad de Guatemala'],
        ]);

        $customer = StorefrontCustomer::query()->findOrFail(77);
        $this->token = $customer->createToken('mobile-ios-address', ['mobile:account'], now()->addDay())->plainTextToken;
    }

    public function test_it_adds_and_lists_an_authoritative_primary_address_for_the_authenticated_user(): void
    {
        $this->withToken($this->token)->postJson('/api/mobile/v1/account/addresses?countryId=1', [
            'idUser' => 88,
            'sameD' => 'NO',
            'paisEntrega' => 'El Salvador',
            'departamentoEntrega' => 10,
            'municipioEntrega' => 100,
            'departamentoEntregaTxt' => 'Texto falso',
            'municipioEntregaTxt' => 'Texto falso',
            'direccionEntrega' => 'Colonia Escalon, avenida principal',
            'referencia' => 'Porton negro',
            'personaRecibe' => 'Ana Lopez',
            'telefonoRecibe' => '70000000',
            'tipoDireccion' => 'CASA',
        ])->assertOk()->assertExactJson(['resultado' => 'true', 'mensaje' => 'exito.']);

        $this->assertDatabaseHas('stj_direcciones', [
            'dir_usuario' => 77,
            'dir_pais' => 'El Salvador',
            'dir_departamento_txt' => 'San Salvador',
            'dir_municipio_txt' => 'San Salvador Centro',
            'dir_principal' => 'SI',
        ]);

        $this->withToken($this->token)->getJson('/api/mobile/v1/account/addresses?countryId=1')
            ->assertOk()->assertJsonPath('resultado', true)->assertJsonCount(1, 'datos')
            ->assertJsonPath('datos.0.dir_usuario', 77);
        $this->withToken($this->token)->getJson('/api/mobile/v1/account/addresses/primary?countryId=1')
            ->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.dir_principal', 'SI');
    }

    public function test_it_changes_primary_only_for_an_owned_address_in_the_selected_country(): void
    {
        DB::table('stj_direcciones')->insert([
            ['dir_id' => 1, 'dir_usuario' => 77, 'dir_pais' => 'El Salvador', 'dir_save' => 'SI', 'dir_principal' => 'SI'],
            ['dir_id' => 2, 'dir_usuario' => 77, 'dir_pais' => 'El Salvador', 'dir_save' => 'SI', 'dir_principal' => 'NO'],
            ['dir_id' => 3, 'dir_usuario' => 88, 'dir_pais' => 'El Salvador', 'dir_save' => 'SI', 'dir_principal' => 'SI'],
            ['dir_id' => 4, 'dir_usuario' => 77, 'dir_pais' => 'Guatemala', 'dir_save' => 'SI', 'dir_principal' => 'NO'],
        ]);

        $this->withToken($this->token)->putJson('/api/mobile/v1/account/addresses/2/primary?countryId=1')
            ->assertOk()->assertJsonPath('resultado', true);
        $this->assertDatabaseHas('stj_direcciones', ['dir_id' => 1, 'dir_principal' => 'NO']);
        $this->assertDatabaseHas('stj_direcciones', ['dir_id' => 2, 'dir_principal' => 'SI']);

        $this->withToken($this->token)->putJson('/api/mobile/v1/account/addresses/3/primary?countryId=1')->assertNotFound();
        $this->withToken($this->token)->putJson('/api/mobile/v1/account/addresses/4/primary?countryId=1')->assertNotFound();
    }

    public function test_it_rejects_cross_country_or_invalid_location_data(): void
    {
        $payload = [
            'sameD' => 'NO', 'paisEntrega' => 'Guatemala', 'departamentoEntrega' => 20,
            'municipioEntrega' => 200, 'direccionEntrega' => 'Direccion suficientemente larga',
            'personaRecibe' => 'Ana Lopez', 'telefonoRecibe' => '70000000', 'tipoDireccion' => 'CASA',
        ];
        $this->withToken($this->token)->postJson('/api/mobile/v1/account/addresses?countryId=1', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('paisEntrega');

        $payload['paisEntrega'] = 'El Salvador';
        $this->withToken($this->token)->postJson('/api/mobile/v1/account/addresses?countryId=1', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('municipioEntrega');
    }
}
