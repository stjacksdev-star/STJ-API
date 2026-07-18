<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stj_carrito_auditoria', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('cau_id', true);
            $table->bigInteger('cau_carrito_id');
            $table->bigInteger('cau_detalle_id')->nullable();
            $table->bigInteger('cau_visitante_id')->nullable();
            $table->bigInteger('cau_usu_id')->nullable();
            $table->string('cau_accion', 32);
            $table->string('cau_origen', 20)->default('WEB');
            $table->unsignedInteger('cau_cantidad_anterior')->nullable();
            $table->unsignedInteger('cau_cantidad_nueva')->nullable();
            $table->json('cau_datos_anteriores')->nullable();
            $table->json('cau_datos_nuevos')->nullable();
            $table->dateTime('cau_ocurrido_en', 6);

            $table->foreign('cau_carrito_id')->references('car_id')->on('stj_carritos');
            $table->foreign('cau_detalle_id')->references('cad_id')->on('stj_carrito_detalles');
            $table->foreign('cau_visitante_id')->references('vis_id')->on('stj_visitantes');
            $table->foreign('cau_usu_id')->references('usu_id')->on('stj_usuarios');
            $table->index(['cau_carrito_id', 'cau_ocurrido_en']);
            $table->index(['cau_usu_id', 'cau_ocurrido_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_carrito_auditoria');
    }
};
