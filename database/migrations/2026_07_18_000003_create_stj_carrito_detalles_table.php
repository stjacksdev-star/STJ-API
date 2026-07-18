<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stj_carrito_detalles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('cad_id', true);
            $table->bigInteger('cad_carrito_id');
            $table->bigInteger('cad_producto_id');
            $table->bigInteger('cad_promocion_id')->nullable();
            $table->string('cad_talla', 10);
            $table->string('cad_ref', 50)->nullable();
            $table->unsignedInteger('cad_cantidad');
            $table->decimal('cad_precio_unitario', 12, 2);
            $table->decimal('cad_descuento_unitario', 12, 2)->default(0);
            $table->decimal('cad_precio_final_unitario', 12, 2);
            $table->string('cad_promocion', 150)->nullable();
            $table->boolean('cad_seleccionado')->default(true);
            $table->dateTime('cad_creado_en', 6);
            $table->dateTime('cad_actualizado_en', 6);

            $table->foreign('cad_carrito_id')->references('car_id')->on('stj_carritos');
            $table->foreign('cad_producto_id')->references('pro_id')->on('stj_productos');
            $table->foreign('cad_promocion_id')->references('prm_id')->on('stj_promociones');
            $table->index(['cad_carrito_id', 'cad_producto_id']);
            $table->index('cad_promocion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_carrito_detalles');
    }
};
