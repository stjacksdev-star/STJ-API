<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stj_powertranz_operaciones', fn (Blueprint $table) => $table->text('pto_respuesta_segura')->nullable()->change());
    }

    public function down(): void
    {
        // El contenido cifrado no es JSON; no se revierte a un tipo JSON.
    }
};
