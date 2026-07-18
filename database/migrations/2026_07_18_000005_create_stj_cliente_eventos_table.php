<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stj_cliente_eventos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('cev_id', true);
            $table->char('cev_event_uuid', 36);
            $table->bigInteger('cev_visitante_id');
            $table->bigInteger('cev_usu_id')->nullable();
            $table->bigInteger('cev_pais_id')->nullable();
            $table->bigInteger('cev_producto_id')->nullable();
            $table->bigInteger('cev_pedido_id')->nullable();
            $table->bigInteger('cev_carrito_id')->nullable();
            $table->string('cev_tipo', 32);
            $table->unsignedInteger('cev_cantidad')->nullable();
            $table->decimal('cev_valor', 12, 2)->nullable();
            $table->char('cev_moneda', 3)->nullable();
            $table->string('cev_origen', 20)->default('WEB');
            $table->dateTime('cev_ocurrido_en', 6);
            $table->dateTime('cev_recibido_en', 6);
            $table->json('cev_metadata')->nullable();

            $table->foreign('cev_visitante_id', 'fk_cev_visitante')->references('vis_id')->on('stj_visitantes');
            $table->foreign('cev_usu_id', 'fk_cev_usuario')->references('usu_id')->on('stj_usuarios');
            $table->foreign('cev_pais_id', 'fk_cev_pais')->references('pai_id')->on('stj_paises');
            $table->foreign('cev_producto_id', 'fk_cev_producto')->references('pro_id')->on('stj_productos');
            $table->foreign('cev_pedido_id', 'fk_cev_pedido')->references('ped_id')->on('stj_pedidos');
            $table->foreign('cev_carrito_id', 'fk_cev_carrito')->references('car_id')->on('stj_carritos');
            $table->unique('cev_event_uuid', 'uq_cev_event_uuid');
            $table->index(['cev_visitante_id', 'cev_ocurrido_en'], 'idx_cev_visitante_fecha');
            $table->index(['cev_usu_id', 'cev_ocurrido_en'], 'idx_cev_usuario_fecha');
            $table->index(['cev_producto_id', 'cev_tipo', 'cev_ocurrido_en'], 'idx_cev_producto_tipo_fecha');
            $table->index(['cev_pais_id', 'cev_tipo', 'cev_ocurrido_en'], 'idx_cev_pais_tipo_fecha');
            $table->index('cev_pedido_id', 'idx_cev_pedido');
            $table->index('cev_carrito_id', 'idx_cev_carrito');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_cliente_eventos');
    }
};
