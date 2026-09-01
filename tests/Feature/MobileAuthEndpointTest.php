<?php

namespace Tests\Feature;

use App\Models\StorefrontCustomer;
use App\Services\Mobile\MobilePushSubscriptionService;
use App\Services\StorefrontPasswordResetService;
use App\Services\StorefrontWelcomeCouponService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
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
            $table->string('usu_telefono_w_pais')->nullable();
            $table->string('usu_telefono_w')->nullable();
            $table->string('usu_foto_perfil')->nullable();
            $table->date('usu_fecha_nacimiento')->nullable();
            $table->string('usu_tipo_login')->nullable();
            $table->string('usu_perfil')->nullable();
            $table->dateTime('usu_fecha_registro')->nullable();
            $table->boolean('usu_suscrito_mailing')->default(false);
            $table->bigInteger('usu_pais_registro')->nullable();
        });
        Schema::create('stj_world_countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phonecode');
        });
        Schema::create('stj_world_states', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('country_id');
            $table->string('name');
            $table->unsignedTinyInteger('estado')->default(1);
        });
        Schema::create('stj_world_cities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('state_id');
            $table->bigInteger('country_id');
            $table->string('name');
        });
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->bigInteger('ped_id')->primary();
            $table->bigInteger('ped_id_pais');
            $table->bigInteger('ped_user')->nullable();
            $table->string('ped_email')->nullable();
            $table->string('ped_checkout')->nullable();
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->bigInteger('ppa_id')->primary();
            $table->bigInteger('ppa_pedido');
            $table->string('ppa_estado');
            $table->string('ppa_ref')->nullable();
            $table->dateTime('ppa_fecha')->nullable();
            $table->integer('ppa_articulos')->nullable();
            $table->decimal('ppa_monto', 10, 2)->nullable();
            $table->string('ppa_tipo')->nullable();
            $table->string('ppa_tarjeta')->nullable();
        });
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_world_countries')->insert(['id' => 1, 'name' => 'El Salvador', 'phonecode' => '503']);
        DB::table('stj_world_states')->insert([
            ['id' => 10, 'country_id' => 1, 'name' => 'San Salvador'],
            ['id' => 20, 'country_id' => 2, 'name' => 'Guatemala'],
        ]);
        DB::table('stj_world_cities')->insert([
            ['id' => 11, 'state_id' => 10, 'country_id' => 1, 'name' => 'Santa Tecla'],
            ['id' => 21, 'state_id' => 20, 'country_id' => 2, 'name' => 'Guatemala'],
        ]);
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
    }

    public function test_it_registers_a_mobile_customer_with_the_validated_registration_country(): void
    {
        $welcome = Mockery::mock(StorefrontWelcomeCouponService::class);
        $welcome->shouldReceive('issue')->once()->with(1, 'SV', 'nuevo@example.com', 'Nuevo Cliente')->andReturn(null);
        $welcome->shouldReceive('sendWelcomeEmail')->once()->with(null);
        $this->app->instance(StorefrontWelcomeCouponService::class, $welcome);

        $response = $this->postJson('/api/mobile/v1/auth/register?countryId=1', [
            'nombres' => 'Nuevo',
            'apellidos' => 'Cliente',
            'email' => 'NUEVO@example.com',
            'fechaNac' => '1995-05-10',
            'pais' => 'Honduras',
            'telefono' => '70001111',
            'password' => 'Clave123',
            'idSesion' => 'installation-register',
            'dispositivo' => 'ANDROID',
        ]);

        $response->assertCreated()
            ->assertJsonPath('resultado', 'true')
            ->assertJsonPath('correo', 'nuevo@example.com')
            ->assertJsonStructure(['idUser', 'accessToken', 'expiresAt']);

        $this->assertDatabaseHas('stj_usuarios', [
            'usu_correo' => 'nuevo@example.com',
            'usu_pais_registro' => 1,
            'usu_pais' => 'El Salvador',
            'usu_tipo_login' => 'APP',
        ]);
    }

    public function test_mobile_registration_rejects_a_country_outside_the_app_scope(): void
    {
        DB::table('stj_paises')->insert(['pai_id' => 5, 'pai_codigo' => 'PA']);

        $this->postJson('/api/mobile/v1/auth/register?countryId=5', [
            'nombres' => 'Nuevo', 'apellidos' => 'Cliente', 'email' => 'nuevo@example.com',
            'telefono' => '70001111', 'password' => 'Clave123', 'dispositivo' => 'IOS',
        ])->assertUnprocessable()->assertJsonPath('resultado', 'false');

        $this->assertDatabaseMissing('stj_usuarios', ['usu_correo' => 'nuevo@example.com']);
    }

    public function test_mobile_password_recovery_uses_the_shared_secure_reset_flow(): void
    {
        $resets = Mockery::mock(StorefrontPasswordResetService::class);
        $resets->shouldReceive('request')
            ->once()
            ->with('cliente@example.com', 'SV', '127.0.0.1')
            ->andReturn(true);
        $this->app->instance(StorefrontPasswordResetService::class, $resets);

        $this->postJson('/api/mobile/v1/auth/recovery?countryId=1', [
            'email' => ' Cliente@Example.com ',
        ])->assertOk()->assertExactJson([
            'resultado' => 'true',
            'mensaje' => 'Si existe una cuenta con ese correo, enviaremos un enlace para restablecer la contrasena.',
        ]);
    }

    public function test_mobile_password_recovery_rejects_unsupported_country_and_invalid_email(): void
    {
        $resets = Mockery::mock(StorefrontPasswordResetService::class);
        $resets->shouldNotReceive('request');
        $this->app->instance(StorefrontPasswordResetService::class, $resets);

        $this->postJson('/api/mobile/v1/auth/recovery?countryId=99', ['email' => 'cliente@example.com'])
            ->assertUnprocessable()->assertJsonPath('resultado', 'false');
        $this->postJson('/api/mobile/v1/auth/recovery?countryId=1', ['email' => 'correo-invalido'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_push_link_failure_does_not_block_a_valid_login(): void
    {
        $push = Mockery::mock(MobilePushSubscriptionService::class);
        $push->shouldReceive('attachCustomer')->once()->andThrow(new \RuntimeException('Push schema unavailable'));
        $this->app->instance(MobilePushSubscriptionService::class, $push);

        $this->postJson('/api/mobile/v1/auth/login?countryId=1', [
            'email' => 'cliente@example.com',
            'password' => 'ClaveSegura123',
            'idSesion' => 'installation-123',
            'installationId' => '9d587183-10e7-4119-ae59-6e1c2f90fd14',
            'environment' => 'TEST',
            'dispositivo' => 'WEB',
        ])->assertOk()
            ->assertJsonPath('resultado', 'true')
            ->assertJsonStructure(['accessToken', 'expiresAt']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => 77,
            'name' => 'mobile-web-'.substr(hash('sha256', 'installation-123'), 0, 16),
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

    public function test_authenticated_mobile_customer_can_change_password_and_other_sessions_are_revoked(): void
    {
        $resets = Mockery::mock(StorefrontPasswordResetService::class);
        $resets->shouldReceive('sendPasswordChangedNotification')->once()->andReturn(true);
        $this->app->instance(StorefrontPasswordResetService::class, $resets);
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $current = $customer->createToken('mobile-ios-password', ['mobile:account'], now()->addDays(30));
        $other = $customer->createToken('mobile-android-password', ['mobile:account'], now()->addDays(30));

        $this->withToken($current->plainTextToken)->putJson('/api/mobile/v1/auth/password', [
            'current_password' => 'ClaveSegura123',
            'password' => 'NuevaClave456',
            'password_confirmation' => 'NuevaClave456',
        ])->assertOk()->assertExactJson([
            'resultado' => 'true',
            'mensaje' => 'Tu contrasena fue actualizada correctamente.',
        ]);

        $this->assertTrue(Hash::check('NuevaClave456', StorefrontCustomer::query()->findOrFail(77)->usu_password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->getKey()]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->getKey()]);
    }

    public function test_mobile_password_change_requires_the_current_password_and_ignores_user_ids(): void
    {
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $token = $customer->createToken('mobile-ios-password-invalid', ['mobile:account'], now()->addDays(30));

        $this->withToken($token->plainTextToken)->putJson('/api/mobile/v1/auth/password', [
            'user' => 999,
            'current_password' => 'ClaveIncorrecta',
            'password' => 'NuevaClave456',
            'password_confirmation' => 'NuevaClave456',
        ])->assertOk()->assertExactJson([
            'resultado' => 'false',
            'mensaje' => 'La contrasena actual no es correcta.',
        ]);

        $this->assertTrue(Hash::check('ClaveSegura123', StorefrontCustomer::query()->findOrFail(77)->usu_password));
    }

    public function test_mobile_account_deletion_uses_the_authenticated_customer_and_revokes_all_sessions(): void
    {
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $current = $customer->createToken('mobile-ios-delete', ['mobile:account'], now()->addDays(30));
        $other = $customer->createToken('mobile-android-delete', ['mobile:account'], now()->addDays(30));

        $this->withToken($current->plainTextToken)->deleteJson('/api/mobile/v1/account', [
            'password' => 'ClaveSegura123',
            'confirmation' => 'ELIMINAR',
            'id_usuario' => 999,
        ])->assertOk()->assertExactJson([
            'resultado' => 'true',
            'mensaje' => 'Tu cuenta fue eliminada y todas las sesiones fueron cerradas.',
        ]);

        $this->assertDatabaseHas('stj_usuarios', [
            'usu_id' => 77,
            'usu_usuario' => 'cliente@example.com_deleted',
            'usu_activo' => 0,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->getKey()]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->getKey()]);
    }

    public function test_mobile_account_deletion_requires_the_current_password_and_confirmation(): void
    {
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $token = $customer->createToken('mobile-ios-delete-invalid', ['mobile:account'], now()->addDays(30));

        $this->withToken($token->plainTextToken)->deleteJson('/api/mobile/v1/account', [
            'password' => 'Incorrecta123',
            'confirmation' => 'ELIMINAR',
        ])->assertOk()->assertJsonPath('resultado', 'false');

        $this->withToken($token->plainTextToken)->deleteJson('/api/mobile/v1/account', [
            'password' => 'ClaveSegura123',
            'confirmation' => 'BORRAR',
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmation');

        $this->assertDatabaseHas('stj_usuarios', ['usu_id' => 77, 'usu_activo' => 1]);
    }

    public function test_it_reads_and_updates_only_the_authenticated_mobile_account(): void
    {
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $token = $customer->createToken('mobile-ios-account', ['mobile:account'], now()->addDays(30));

        $this->withToken($token->plainTextToken)->getJson('/api/mobile/v1/account')
            ->assertOk()
            ->assertJsonPath('resultado', 'true')
            ->assertJsonPath('idUser', 77)
            ->assertJsonPath('nombres', 'Ana');

        $this->withToken($token->plainTextToken)->putJson('/api/mobile/v1/account', [
            'form1' => [
                'idUser' => 999,
                'nombres' => 'Ana Maria',
                'apellidos' => 'Lopez Perez',
                'email' => 'nuevo@example.com',
                'tipoIdentificacion' => 'DUI',
                'identificacion' => '01234567-8',
                'pais' => 'El Salvador',
                'departamento' => 10,
                'municipio' => 11,
                'estado' => 'Texto manipulado',
                'ciudad' => 'San Salvador',
                'direccion' => 'Colonia Escalon',
                'telefono' => '70001111',
                'whatsapp' => '70002222',
            ],
        ])->assertOk()
            ->assertJsonPath('resultado', 'true')
            ->assertJsonPath('idUser', 77)
            ->assertJsonPath('correo', 'nuevo@example.com')
            ->assertJsonPath('departamento', 'San Salvador');

        $this->assertDatabaseHas('stj_usuarios', [
            'usu_id' => 77,
            'usu_usuario' => 'nuevo@example.com',
            'usu_correo' => 'nuevo@example.com',
            'usu_nombre' => 'Ana Maria',
            'usu_departamento_id' => 10,
            'usu_estado' => 'San Salvador',
            'usu_telefono_pais' => '+503',
        ]);
    }

    public function test_it_returns_only_approved_orders_for_the_authenticated_customer_and_country(): void
    {
        DB::table('stj_paises')->insert(['pai_id' => 2, 'pai_codigo' => 'GT']);
        DB::table('stj_pedidos')->insert([
            ['ped_id' => 100, 'ped_id_pais' => 1, 'ped_user' => 77, 'ped_email' => 'ana@example.com', 'ped_checkout' => 'TIENDA'],
            ['ped_id' => 101, 'ped_id_pais' => 1, 'ped_user' => 77, 'ped_email' => 'ana@example.com', 'ped_checkout' => 'DOMICILIO'],
            ['ped_id' => 102, 'ped_id_pais' => 2, 'ped_user' => 77, 'ped_email' => 'ana@example.com', 'ped_checkout' => 'TIENDA'],
            ['ped_id' => 103, 'ped_id_pais' => 1, 'ped_user' => 999, 'ped_email' => 'otra@example.com', 'ped_checkout' => 'TIENDA'],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_id' => 1, 'ppa_pedido' => 100, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ100', 'ppa_fecha' => '2026-09-01 10:30:00', 'ppa_articulos' => 2, 'ppa_monto' => 25.50, 'ppa_tipo' => 'TARJETA', 'ppa_tarjeta' => '****0006'],
            ['ppa_id' => 2, 'ppa_pedido' => 101, 'ppa_estado' => 'DENEGADA', 'ppa_ref' => 'STJ101', 'ppa_fecha' => '2026-09-01 11:30:00', 'ppa_articulos' => 1, 'ppa_monto' => 10, 'ppa_tipo' => 'TARJETA', 'ppa_tarjeta' => '****0006'],
            ['ppa_id' => 3, 'ppa_pedido' => 102, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ102', 'ppa_fecha' => '2026-09-01 12:30:00', 'ppa_articulos' => 1, 'ppa_monto' => 12, 'ppa_tipo' => 'EFECTIVO', 'ppa_tarjeta' => null],
            ['ppa_id' => 4, 'ppa_pedido' => 103, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ103', 'ppa_fecha' => '2026-09-01 13:30:00', 'ppa_articulos' => 1, 'ppa_monto' => 15, 'ppa_tipo' => 'EFECTIVO', 'ppa_tarjeta' => null],
        ]);

        $customer = StorefrontCustomer::query()->findOrFail(77);
        $token = $customer->createToken('mobile-ios-orders', ['mobile:account'], now()->addDays(30));

        $this->withToken($token->plainTextToken)
            ->getJson('/api/mobile/v1/account/orders?countryId=1')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.id', 100)
            ->assertJsonPath('records.0.ref', 'STJ100')
            ->assertJsonPath('records.0.articulos', 2)
            ->assertJsonPath('records.0.compra', 25.5)
            ->assertJsonPath('records.0.pago', 'TARJETA ****0006');
    }

    public function test_account_update_rejects_duplicate_email_and_cross_country_department(): void
    {
        DB::table('stj_usuarios')->insert([
            'usu_id' => 88,
            'usu_usuario' => 'ocupado@example.com',
            'usu_correo' => 'ocupado@example.com',
            'usu_password' => Hash::make('OtraClave123'),
            'usu_activo' => 1,
        ]);
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $token = $customer->createToken('mobile-ios-validation', ['mobile:account'], now()->addDays(30));
        $base = [
            'nombres' => 'Ana', 'apellidos' => 'Lopez', 'tipoIdentificacion' => '', 'identificacion' => '',
            'pais' => 'El Salvador', 'departamento' => 10, 'municipio' => 11, 'estado' => '', 'ciudad' => 'Santa Tecla', 'direccion' => '',
            'telefono' => '70000000', 'whatsapp' => '',
        ];

        $this->withToken($token->plainTextToken)->putJson('/api/mobile/v1/account', [
            'form1' => $base + ['email' => 'ocupado@example.com'],
        ])->assertOk()->assertJsonPath('resultado', 'false');

        $this->withToken($token->plainTextToken)->putJson('/api/mobile/v1/account', [
            'form1' => array_merge($base, ['email' => 'cliente@example.com', 'departamento' => 20]),
        ])->assertUnprocessable()->assertJsonValidationErrors('form1.departamento');
    }

    public function test_account_update_accepts_the_authenticated_email_and_requires_municipality(): void
    {
        DB::table('stj_usuarios')->insert([
            'usu_id' => 88,
            'usu_usuario' => 'cliente@example.com',
            'usu_correo' => 'cliente@example.com',
            'usu_password' => Hash::make('OtraClave123'),
            'usu_activo' => 1,
        ]);
        $customer = StorefrontCustomer::query()->findOrFail(77);
        $token = $customer->createToken('mobile-ios-current-email', ['mobile:account'], now()->addDays(30));
        $form = [
            'nombres' => 'Ana',
            'apellidos' => 'Lopez',
            'email' => 'cliente@example.com',
            'tipoIdentificacion' => 'DUI',
            'identificacion' => '01234567-8',
            'pais' => 'El Salvador',
            'departamento' => 10,
            'municipio' => 11,
            'estado' => 'San Salvador',
            'ciudad' => 'Santa Tecla',
            'direccion' => 'Colonia Escalon',
            'telefono' => '70000000',
            'whatsapp' => '',
        ];

        $this->withToken($token->plainTextToken)
            ->putJson('/api/mobile/v1/account', ['form1' => $form])
            ->assertOk()
            ->assertJsonPath('resultado', 'true');

        $this->withToken($token->plainTextToken)
            ->putJson('/api/mobile/v1/account', ['form1' => array_merge($form, ['municipio' => ''])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form1.municipio');
    }
}
