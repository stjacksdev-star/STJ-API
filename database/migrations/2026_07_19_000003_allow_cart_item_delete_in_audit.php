<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stj_carrito_auditoria', function (Blueprint $table) {
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite'
                ? ['cau_detalle_id']
                : 'stj_carrito_auditoria_cau_detalle_id_foreign');
            $table->foreign('cau_detalle_id', 'fk_cau_detalle')
                ->references('cad_id')->on('stj_carrito_detalles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stj_carrito_auditoria', function (Blueprint $table) {
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite'
                ? ['cau_detalle_id']
                : 'fk_cau_detalle');
            $table->foreign('cau_detalle_id', 'stj_carrito_auditoria_cau_detalle_id_foreign')
                ->references('cad_id')->on('stj_carrito_detalles');
        });
    }
};
