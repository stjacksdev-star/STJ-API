<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_producto_metricas') && ! Schema::hasIndex('stj_producto_metricas', 'idx_pme_ranking_vistas')) {
            Schema::table('stj_producto_metricas', function (Blueprint $table) {
                $table->index(['pme_pais', 'pme_periodo', 'pme_ranking_vistas'], 'idx_pme_ranking_vistas');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stj_producto_metricas') && Schema::hasIndex('stj_producto_metricas', 'idx_pme_ranking_vistas')) {
            Schema::table('stj_producto_metricas', function (Blueprint $table) {
                $table->dropIndex('idx_pme_ranking_vistas');
            });
        }
    }
};
