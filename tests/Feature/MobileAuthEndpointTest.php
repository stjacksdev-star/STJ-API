<?php

namespace Tests\Feature;

use App\Models\StorefrontCustomer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileAuthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->bigInteger('pai_id')->primary();
            $table->string('pai_codigo');
        });
        Schema::create('stj_usuarios', function (Blueprint $table) {
            $table->id('usu_id');
            $table->string('usu_usuario');
            $table->string('usu_password');
            $table->boolean('usu_activo')->default(true);
            $table->string('usu_nombre')->nullable();
            $table->string('usu_apellido')->nullable();
            $table->string('usu_telefono_pais')->nullable();
            $table->string('usu_telefono')->nullable();
            $table->string('usu_correo')->nullable();
            $table->string('usu_tipo_identificacion')->nullable();
            $table->string('usu_identificacion')->nullable();
            $table->string('usu_pais')->nullable();
            $table->bigInteger('usu_departamento_id')->nullable();
            $table->bigInteger('usu_municipio_id')->nullable();
            $table->string('usu_departamento_txt')->nullable();
            $table->string('usu_municipio_txt')->nullable();
            $table->string('usu_estado')->nullable();
            $table->string('usu_ciudad')->nullable();
            $table->string('usu_direccion')->nullable();
            $table->string('usu_telefono_w')->nullable();
            $table->string('usu_foto_perfil')->nullable();
        });
        Schema::create('stj_usuarios_dispositivos', function (Blueprint $table) {
            $table->id('dis_id');
            $table->bigInteger('dis_user');
            $table->string('dis_token');
            $table->timestamp('dis_fecha')->nullable();
            $table->string('dis_tipo_dispositivo');
            $table->unique(['dis_user', 'dis_token']);
        });

        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_usuarios')->insert([
            'usu_id' => 77,
            'usu_usuario' => 'cliente@example.com',
            'usu_password' => Hash::make('ClaveSegura123'),
            'usu_activo' => 1,
            'usu_nombre' => 'Ana',
            'usu_apellido' => 'Lopez',
            'usu_telefono_pais' => '+503',
            'usu_telefono' => '70000000',
            'usu_correo' => 'cliente@example.com',
        ]);
    }

    public function test_it_preserves_the_mobile_contract_and_issues_a_device_token(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/login?countryId=1', [
            'email' => ' Cliente@Example.com ',
            'password' => 'ClaveSegura123',
            'token' => 'fcm-device-token',
            'idSesion' => 'installation-123',
            'dispositivo' => 'IOS',
        ]);

        $response->assertOk()
            ->assertJsonPath('resultado', 'true')
            ->assertJsonPath('idUser', 77)
            ->assertJsonPath('nombre', 'Ana Lopez')
            ->assertJsonPath('correo', 'cliente@example.com')
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonStructure(['accessToken', 'expiresAt']);

        $plainTextToken = (string) $response->json('accessToken');
        [, $secret] = explode('|', $plainTextToken, 2);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => 'App\\Models\\StorefrontCustomer',
            'tokenable_id' => 77,
            'name' => 'mobile-ios-'.substr(hash('sha256', 'installation-123'), 0, 16),
            'token' => hash('sha256', $secret),
        ]);
        $this->assertDatabaseHas('stj_usuarios_dispositivos', [
            'dis_user' => 77,
            'dis_token' => 'fcm-device-token',
            'dis_tipo_dispositivo' => 'IOS',
        ]);
    }

    public function test_it_keeps_legacy_credential_error_messages_without_issuing_tokens(): void
    {
        $payload = ['password' => 'incorrecta', 'token' => '', 'idSesion' => 'abc', 'dispositivo' => 'ANDROID'];

        $this->postJson('/api/mobile/v1/auth/login?countryId=1', $payload + ['email' => 'cliente@example.com'])
            ->assertOk()->assertExactJson(['resultado' => 'false', 'mensaje' => "Contrase\u{00F1}a incorrecta"]);
        $this->postJson('/api/mobile/v1/auth/login?countryId=1', $payload + ['email' => 'nadie@example.com'])
            ->assertOk()->assertExactJson(['resultado' => 'false', 'mensaje' => 'Usuario no registrado']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_validates_country_and_device_platform(): void
    {
        $this->postJson('/api/mobile/v1/auth/login?countryId=99', [
            'email' => 'cliente@example.com',
            'password' => 'ClaveSegura123',
            'dispositivo' => 'IOS',
        ])->assertUnprocessable()->assertJsonPath('resultado', 'false');

        $this->postJson('/api/mobile/v1/auth/login?countryId=1', [
            'email' => 'cliente@example.com',
            'password' => 'ClaveSegura123',
            'dispositivo' => 'WINDOWS',
        ])->assertUnprocessable()->assertJsonValidationErrors('dispositivo');
    }

    public function test_it_restores_the_profile_and_revokes_only_the_current_device_token(): void
    {
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $current = $customer->createToken('mobile-ios-current', ['mobile:account'], now()->addDays(30));
        $other = $customer->createToken('mobile-android-other', ['mobile:account'], now()->addDays(30));

        $headers = ['Authorization' => 'Bearer '.$current->plainTextToken];
        $this->withHeaders($headers)->getJson('/api/mobile/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('resultado', 'true')
            ->assertJsonPath('idUser', 77)
            ->assertJsonStructure(['expiresAt']);

        $this->withHeaders($headers)->postJson('/api/mobile/v1/auth/logout')
            ->assertOk()
            ->assertExactJson(['resultado' => 'true', 'mensaje' => 'Sesion cerrada.']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->getKey()]);
        Auth::forgetGuards();
        $this->withHeaders($headers)->getJson('/api/mobile/v1/auth/session')->assertUnauthorized();
    }

    public function test_mobile_session_rejects_a_non_mobile_customer_token(): void
    {
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $webToken = $customer->createToken('storefront-customer', ['storefront:account'], now()->addHours(3));

        $this->withToken($webToken->plainTextToken)
            ->getJson('/api/mobile/v1/auth/session')
            ->assertForbidden()
            ->assertJsonPath('resultado', 'false');
    }
}
