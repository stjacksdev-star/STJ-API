<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stj_visitantes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('vis_id', true);
            $table->char('vis_uuid', 36)->unique();
            $table->string('vis_origen', 20)->default('WEB');
            $table->bigInteger('vis_pais_id')->nullable();
            $table->dateTime('vis_primera_visita', 6);
            $table->dateTime('vis_ultima_visita', 6);
            $table->dateTime('vis_expira_en', 6);
            $table->dateTime('vis_creado_en', 6);
            $table->dateTime('vis_actualizado_en', 6);

            $table->foreign('vis_pais_id')->references('pai_id')->on('stj_paises');
            $table->index(['vis_pais_id', 'vis_ultima_visita']);
            $table->index('vis_expira_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_visitantes');
    }
};
