<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_push_automatizaciones')) {
            return;
        }

        Schema::create('stj_push_automatizaciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('pau_id', true);
            $table->string('pau_codigo', 64);
            $table->string('pau_nombre', 150);
            $table->text('pau_descripcion')->nullable();
            $table->enum('pau_estado', ['ACTIVA', 'INACTIVA'])->default('INACTIVA');
            $table->json('pau_paises')->nullable();
            $table->unsignedInteger('pau_retraso_minutos')->default(120);
            $table->unsignedInteger('pau_cooldown_horas')->default(24);
            $table->unsignedSmallInteger('pau_maximo_por_entidad')->default(1);
            $table->string('pau_titulo_plantilla', 160);
            $table->string('pau_cuerpo_plantilla', 500);
            $table->string('pau_action_plantilla', 500);
            $table->string('pau_imagen', 500)->nullable();
            $table->json('pau_configuracion')->nullable();
            $table->dateTime('pau_creado_en', 6);
            $table->dateTime('pau_actualizado_en', 6);

            $table->unique('pau_codigo', 'uq_pau_codigo');
            $table->index(['pau_estado', 'pau_codigo'], 'idx_pau_estado_codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_push_automatizaciones');
    }
};
