<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_pedidos') && ! Schema::hasColumn('stj_pedidos', 'ped_plataforma')) {
            Schema::table('stj_pedidos', function (Blueprint $table) {
                $table->string('ped_plataforma', 10)->nullable()->after('ped_origen');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stj_pedidos') && Schema::hasColumn('stj_pedidos', 'ped_plataforma')) {
            Schema::table('stj_pedidos', function (Blueprint $table) {
                $table->dropColumn('ped_plataforma');
            });
        }
    }
};
