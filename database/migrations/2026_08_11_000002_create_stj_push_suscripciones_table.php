<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_push_suscripciones')) {
            return;
        }

        Schema::create('stj_push_suscripciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('psu_id', true);
            $table->bigInteger('psu_visitante_id')->nullable();
            $table->bigInteger('psu_usu_id')->nullable();
            $table->bigInteger('psu_pais_id');
            $table->string('psu_token', 500);
            $table->char('psu_token_hash', 64);
            $table->enum('psu_plataforma', ['WEB'])->default('WEB');
            $table->enum('psu_estado', ['ACTIVA', 'REVOCADA', 'INVALIDA'])->default('ACTIVA');
            $table->enum('psu_permiso', ['GRANTED', 'DENIED', 'DEFAULT'])->default('DEFAULT');
            $table->string('psu_navegador', 100)->nullable();
            $table->string('psu_dispositivo', 100)->nullable();
            $table->string('psu_sistema_operativo', 100)->nullable();
            $table->string('psu_idioma', 20)->nullable();
            $table->string('psu_zona_horaria', 64)->nullable();
            $table->string('psu_user_agent', 500)->nullable();
            $table->dateTime('psu_ultima_actividad_en', 6);
            $table->dateTime('psu_token_actualizado_en', 6)->nullable();
            $table->dateTime('psu_revocado_en', 6)->nullable();
            $table->dateTime('psu_creado_en', 6);
            $table->dateTime('psu_actualizado_en', 6);

            $table->unique('psu_token_hash', 'uq_psu_token_hash');
            $table->index(['psu_visitante_id', 'psu_estado'], 'idx_psu_visitante_estado');
            $table->index(['psu_usu_id', 'psu_estado'], 'idx_psu_usuario_estado');
            $table->index(['psu_pais_id', 'psu_estado', 'psu_ultima_actividad_en'], 'idx_psu_pais_estado_actividad');
            $table->foreign('psu_visitante_id', 'fk_psu_visitante')->references('vis_id')->on('stj_visitantes')->nullOnDelete();
            $table->foreign('psu_usu_id', 'fk_psu_usuario')->references('usu_id')->on('stj_usuarios')->nullOnDelete();
            $table->foreign('psu_pais_id', 'fk_psu_pais')->references('pai_id')->on('stj_paises');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_push_suscripciones');
    }
};
