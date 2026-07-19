<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stj_carrito_operaciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('cao_id', true);
            $table->char('cao_uuid', 36);
            $table->bigInteger('cao_carrito_id');
            $table->bigInteger('cao_visitante_id');
            $table->bigInteger('cao_usu_id')->nullable();
            $table->string('cao_tipo', 32);
            $table->char('cao_payload_hash', 64);
            $table->json('cao_respuesta');
            $table->dateTime('cao_creado_en', 6);
            $table->unique('cao_uuid', 'uq_cao_uuid');
            $table->index(['cao_carrito_id', 'cao_creado_en'], 'idx_cao_carrito_fecha');
            $table->foreign('cao_carrito_id', 'fk_cao_carrito')->references('car_id')->on('stj_carritos');
            $table->foreign('cao_visitante_id', 'fk_cao_visitante')->references('vis_id')->on('stj_visitantes');
            $table->foreign('cao_usu_id', 'fk_cao_usuario')->references('usu_id')->on('stj_usuarios');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_carrito_operaciones');
    }
};
