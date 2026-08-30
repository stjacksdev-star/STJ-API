<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobilePushSubscriptionEndpointTest extends TestCase
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
        });
        Schema::create('stj_sesiones', function (Blueprint $table) {
            $table->id('ses_id');
            $table->string('ses_origen');
            $table->string('ses_codigo')->unique();
            $table->string('ses_dispositivo')->nullable();
            $table->dateTime('ses_fecha');
            $table->string('ses_url_inicio')->nullable();
            $table->string('ses_ip')->nullable();
        });
        Schema::disableForeignKeyConstraints();
        Schema::drop('stj_push_suscripciones');
        Schema::enableForeignKeyConstraints();
        Schema::create('stj_push_suscripciones', function (Blueprint $table) {
            $table->id('psu_id');
            $table->bigInteger('psu_visitante_id')->nullable();
            $table->bigInteger('psu_usu_id')->nullable();
            $table->bigInteger('psu_pais_id');
            $table->string('psu_token', 500);
            $table->string('psu_token_hash', 64)->unique();
            $table->string('psu_plataforma');
            $table->string('psu_estado');
            $table->string('psu_permiso');
            $table->string('psu_dispositivo')->nullable();
            $table->string('psu_sistema_operativo')->nullable();
            $table->string('psu_idioma')->nullable();
            $table->string('psu_zona_horaria')->nullable();
            $table->string('psu_user_agent')->nullable();
            $table->uuid('psu_instalacion_uuid');
            $table->bigInteger('psu_sesion_id')->nullable();
            $table->string('psu_sesion_codigo')->nullable();
            $table->string('psu_app_version')->nullable();
            $table->string('psu_app_build')->nullable();
            $table->string('psu_entorno');
            $table->string('psu_provider');
            $table->dateTime('psu_ultima_actividad_en');
            $table->dateTime('psu_token_actualizado_en')->nullable();
            $table->dateTime('psu_revocado_en')->nullable();
            $table->dateTime('psu_creado_en');
            $table->dateTime('psu_actualizado_en');
            $table->unique(['psu_instalacion_uuid', 'psu_entorno']);
        });
        Schema::create('stj_push_topics', function (Blueprint $table) {
            $table->id('pto_id');
            $table->string('pto_codigo')->unique();
            $table->string('pto_nombre');
            $table->string('pto_tipo');
            $table->string('pto_estado');
        });
        Schema::create('stj_push_suscripcion_topics', function (Blueprint $table) {
            $table->bigInteger('pst_suscripcion_id');
            $table->bigInteger('pst_topic_id');
            $table->string('pst_origen');
            $table->dateTime('pst_suscrito_en');
            $table->dateTime('pst_expira_en')->nullable();
            $table->unique(['pst_suscripcion_id', 'pst_topic_id']);
        });

        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV'],
            ['pai_id' => 2, 'pai_codigo' => 'GT'],
        ]);
        foreach ([
            ['platform.ios', 'iOS', 'PLATAFORMA'],
            ['country.sv', 'El Salvador', 'PAIS'],
            ['country.gt', 'Guatemala', 'PAIS'],
            ['customer.guest', 'Invitado', 'CLIENTE'],
            ['customer.registered', 'Registrado', 'CLIENTE'],
        ] as [$code, $name, $type]) {
            DB::table('stj_push_topics')->insert([
                'pto_codigo' => $code, 'pto_nombre' => $name, 'pto_tipo' => $type, 'pto_estado' => 'ACTIVO',
            ]);
        }
    }

    public function test_it_registers_once_and_keeps_the_initial_country_when_the_app_country_changes(): void
    {
        $installation = '550e8400-e29b-41d4-a716-446655440000';
        $payload = [
            'token' => 'fcm-token-one',
            'installationId' => $installation,
            'platform' => 'IOS',
            'countryId' => 1,
            'permission' => 'GRANTED',
            'environment' => 'TEST',
        ];

        $first = $this->postJson('/api/mobile/v1/push/subscriptions', $payload)
            ->assertCreated()->assertJsonPath('resultado', 'true')->assertJsonPath('countryId', 1);

        $this->postJson('/api/mobile/v1/push/subscriptions', [
            ...$payload,
            'token' => 'fcm-token-rotated',
            'countryId' => 2,
            'sessionCode' => $first->json('sess'),
        ])->assertCreated()->assertJsonPath('countryId', 1);

        $this->assertDatabaseCount('stj_push_suscripciones', 1);
        $this->assertDatabaseHas('stj_push_suscripciones', [
            'psu_instalacion_uuid' => $installation,
            'psu_pais_id' => 1,
            'psu_token_hash' => hash('sha256', 'fcm-token-rotated'),
            'psu_plataforma' => 'IOS',
        ]);
        $this->assertDatabaseHas('stj_push_suscripcion_topics', [
            'pst_topic_id' => DB::table('stj_push_topics')->where('pto_codigo', 'country.sv')->value('pto_id'),
        ]);
    }
}
