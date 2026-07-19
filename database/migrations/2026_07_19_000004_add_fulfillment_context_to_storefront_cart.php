<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stj_carritos', function (Blueprint $table) {
            $table->bigInteger('car_tienda_id')->nullable()->after('car_tipo');
            $table->string('car_tienda_codigo_snapshot', 10)->nullable()->after('car_tienda_id');
            $table->string('car_inventory_source', 40)->nullable()->after('car_tienda_codigo_snapshot');
            $table->index(['car_pais_id', 'car_tienda_id'], 'idx_car_pais_tienda');
            $table->foreign('car_tienda_id', 'fk_car_tienda')->references('tie_id')->on('stj_tiendas');
        });
        Schema::table('stj_carrito_detalles', function (Blueprint $table) {
            $table->string('cad_estado', 32)->default('DISPONIBLE')->after('cad_seleccionado');
            $table->string('cad_motivo_no_disponible', 100)->nullable()->after('cad_estado');
        });
    }

    public function down(): void
    {
        Schema::table('stj_carrito_detalles', fn (Blueprint $table) => $table->dropColumn(['cad_estado', 'cad_motivo_no_disponible']));
        Schema::table('stj_carritos', function (Blueprint $table) {
            $table->dropForeign('fk_car_tienda');
            $table->dropIndex('idx_car_pais_tienda');
            $table->dropColumn(['car_tienda_id', 'car_tienda_codigo_snapshot', 'car_inventory_source']);
        });
    }
};
