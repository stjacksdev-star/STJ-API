<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stj_carritos', fn (Blueprint $table) => $table->string('car_tienda_codigo_snapshot', 15)->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('stj_carritos')->whereRaw('CHAR_LENGTH(car_tienda_codigo_snapshot) > 10')->exists()) {
            throw new RuntimeException('No se puede reducir el snapshot: existen codigos mayores de 10 caracteres.');
        }

        Schema::table('stj_carritos', fn (Blueprint $table) => $table->string('car_tienda_codigo_snapshot', 10)->nullable()->change());
    }
};
