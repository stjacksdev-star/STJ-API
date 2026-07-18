<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stj_carritos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('car_id', true);
            $table->char('car_uuid', 36)->unique();
            $table->bigInteger('car_visitante_id');
            $table->bigInteger('car_usu_id')->nullable();
            $table->bigInteger('car_pais_id');
            $table->bigInteger('car_pedido_id')->nullable();
            $table->string('car_tipo', 20);
            $table->string('car_estado', 20)->default('ACTIVO');
            $table->string('car_origen', 20)->default('WEB');
            $table->char('car_moneda', 3);
            $table->unsignedInteger('car_version')->default(1);
            $table->dateTime('car_ultima_actividad_en', 6);
            $table->dateTime('car_expira_en', 6);
            $table->dateTime('car_checkout_en', 6)->nullable();
            $table->dateTime('car_convertido_en', 6)->nullable();
            $table->dateTime('car_creado_en', 6);
            $table->dateTime('car_actualizado_en', 6);

            $table->foreign('car_visitante_id')->references('vis_id')->on('stj_visitantes');
            $table->foreign('car_usu_id')->references('usu_id')->on('stj_usuarios');
            $table->foreign('car_pais_id')->references('pai_id')->on('stj_paises');
            $table->foreign('car_pedido_id')->references('ped_id')->on('stj_pedidos');
            $table->index(['car_visitante_id', 'car_estado']);
            $table->index(['car_usu_id', 'car_estado']);
            $table->index(['car_estado', 'car_expira_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_carritos');
    }
};
