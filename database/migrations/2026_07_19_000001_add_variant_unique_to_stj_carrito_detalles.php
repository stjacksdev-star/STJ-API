<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stj_carrito_detalles', function (Blueprint $table) {
            $table->string('cad_ref', 50)->nullable(false)->change();
            $table->unique(['cad_carrito_id', 'cad_ref', 'cad_talla'], 'uq_cad_cart_sku_size');
        });
    }

    public function down(): void
    {
        Schema::table('stj_carrito_detalles', function (Blueprint $table) {
            $table->dropUnique('uq_cad_cart_sku_size');
            $table->string('cad_ref', 50)->nullable()->change();
        });
    }
};
