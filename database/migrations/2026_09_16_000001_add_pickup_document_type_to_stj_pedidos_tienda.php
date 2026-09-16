<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('stj_pedidos_tienda') && ! Schema::hasColumn('stj_pedidos_tienda', 'pti_tipo_identificacion')) {
            Schema::table('stj_pedidos_tienda', function (Blueprint $table) {
                $table->string('pti_tipo_identificacion', 50)->nullable()->after('pti_persona');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stj_pedidos_tienda') && Schema::hasColumn('stj_pedidos_tienda', 'pti_tipo_identificacion')) {
            Schema::table('stj_pedidos_tienda', fn (Blueprint $table) => $table->dropColumn('pti_tipo_identificacion'));
        }
    }
};
