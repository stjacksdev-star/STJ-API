<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_push_entregas')) {
            return;
        }

        Schema::create('stj_push_entregas', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('pen_id', true);
            $table->bigInteger('pen_automatizacion_id');
            $table->bigInteger('pen_suscripcion_id')->nullable();
            $table->bigInteger('pen_visitante_id')->nullable();
            $table->bigInteger('pen_usu_id')->nullable();
            $table->bigInteger('pen_pais_id');
            $table->string('pen_entidad_tipo', 50);
            $table->bigInteger('pen_entidad_id');
            $table->unsignedInteger('pen_entidad_version')->nullable();
            $table->string('pen_stage', 50)->default('PRIMARY');
            $table->string('pen_idempotency_key', 191);
            $table->string('pen_titulo', 160);
            $table->string('pen_cuerpo', 500);
            $table->string('pen_action', 500);
            $table->string('pen_imagen', 500)->nullable();
            $table->json('pen_payload')->nullable();
            $table->enum('pen_estado', ['PENDIENTE', 'PROCESANDO', 'ENVIADO', 'REINTENTO', 'DESCARTADO', 'ERROR', 'CANCELADO'])->default('PENDIENTE');
            $table->unsignedSmallInteger('pen_intentos')->default(0);
            $table->dateTime('pen_programado_en', 6);
            $table->dateTime('pen_disponible_en', 6);
            $table->dateTime('pen_bloqueado_en', 6)->nullable();
            $table->string('pen_bloqueado_por', 100)->nullable();
            $table->dateTime('pen_ultimo_intento_en', 6)->nullable();
            $table->dateTime('pen_enviado_en', 6)->nullable();
            $table->text('pen_resultado')->nullable();
            $table->text('pen_error')->nullable();
            $table->dateTime('pen_creado_en', 6);
            $table->dateTime('pen_actualizado_en', 6);

            $table->unique('pen_idempotency_key', 'uq_pen_idempotency_key');
            $table->index(['pen_estado', 'pen_disponible_en', 'pen_programado_en'], 'idx_pen_estado_disponible');
            $table->index(['pen_automatizacion_id', 'pen_estado'], 'idx_pen_automatizacion_estado');
            $table->index(['pen_suscripcion_id', 'pen_creado_en'], 'idx_pen_suscripcion_fecha');
            $table->index(['pen_entidad_tipo', 'pen_entidad_id', 'pen_entidad_version'], 'idx_pen_entidad_version');
            $table->index(['pen_visitante_id', 'pen_creado_en'], 'idx_pen_visitante_fecha');
            $table->index(['pen_usu_id', 'pen_creado_en'], 'idx_pen_usuario_fecha');
            $table->foreign('pen_automatizacion_id', 'fk_pen_automatizacion')->references('pau_id')->on('stj_push_automatizaciones');
            $table->foreign('pen_suscripcion_id', 'fk_pen_suscripcion')->references('psu_id')->on('stj_push_suscripciones')->nullOnDelete();
            $table->foreign('pen_visitante_id', 'fk_pen_visitante')->references('vis_id')->on('stj_visitantes')->nullOnDelete();
            $table->foreign('pen_usu_id', 'fk_pen_usuario')->references('usu_id')->on('stj_usuarios')->nullOnDelete();
            $table->foreign('pen_pais_id', 'fk_pen_pais')->references('pai_id')->on('stj_paises');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_push_entregas');
    }
};
