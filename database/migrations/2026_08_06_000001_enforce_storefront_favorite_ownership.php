<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('stj_favoritos')) {
            Schema::create('stj_favoritos', function (Blueprint $table) {
                $table->bigIncrements('fav_id');
                $table->unsignedBigInteger('fav_pais');
                $table->unsignedBigInteger('fav_visitante')->nullable();
                $table->unsignedBigInteger('fav_usuario')->nullable();
                $table->unsignedBigInteger('fav_producto');
                $table->string('fav_origen', 20)->default('WEB');
                $table->timestamp('fav_created_at')->useCurrent();
                $table->timestamp('fav_updated_at')->useCurrent();
                $table->unique(['fav_visitante', 'fav_pais', 'fav_producto'], 'uq_fav_visitante_pais_producto');
                $table->unique(['fav_usuario', 'fav_pais', 'fav_producto'], 'uq_fav_usuario_pais_producto');
                $table->index('fav_producto', 'idx_favorito_producto');
            });
            if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE stj_favoritos ADD CONSTRAINT chk_fav_owner CHECK (fav_visitante IS NOT NULL OR fav_usuario IS NOT NULL)');
            return;
        }
        DB::table('stj_favoritos')->whereNull('fav_visitante')->whereNull('fav_usuario')->delete();
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DELETE f1 FROM stj_favoritos f1 JOIN stj_favoritos f2 ON f1.fav_id > f2.fav_id AND f1.fav_pais = f2.fav_pais AND f1.fav_producto = f2.fav_producto AND ((f1.fav_usuario IS NOT NULL AND f1.fav_usuario = f2.fav_usuario) OR (f1.fav_usuario IS NULL AND f2.fav_usuario IS NULL AND f1.fav_visitante = f2.fav_visitante))');
            DB::statement('ALTER TABLE stj_favoritos ADD UNIQUE KEY uq_fav_visitante_pais_producto (fav_visitante, fav_pais, fav_producto), ADD UNIQUE KEY uq_fav_usuario_pais_producto (fav_usuario, fav_pais, fav_producto), ADD CONSTRAINT chk_fav_owner CHECK (fav_visitante IS NOT NULL OR fav_usuario IS NOT NULL)');
        }
    }
    public function down(): void
    {
        if (! Schema::hasTable('stj_favoritos')) return;
        if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE stj_favoritos DROP CHECK chk_fav_owner, DROP INDEX uq_fav_visitante_pais_producto, DROP INDEX uq_fav_usuario_pais_producto');
    }
};
