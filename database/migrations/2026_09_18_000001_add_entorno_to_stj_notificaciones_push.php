<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_notificaciones_push') && ! Schema::hasColumn('stj_notificaciones_push', 'npu_entorno')) {
            Schema::table('stj_notificaciones_push', function (Blueprint $table) {
                $table->string('npu_entorno', 16)->default('PRODUCTION');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stj_notificaciones_push') && Schema::hasColumn('stj_notificaciones_push', 'npu_entorno')) {
            Schema::table('stj_notificaciones_push', function (Blueprint $table) {
                $table->dropColumn('npu_entorno');
            });
        }
    }
};
