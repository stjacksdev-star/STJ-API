<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_push_eventos')) {
            return;
        }

        Schema::create('stj_push_eventos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigInteger('pev_id', true);
            $table->bigInteger('pev_entrega_id');
            $table->char('pev_event_uuid', 36)->nullable();
            $table->string('pev_tipo', 32);
            $table->string('pev_origen', 20)->default('WEB');
            $table->json('pev_datos')->nullable();
            $table->dateTime('pev_ocurrido_en', 6);
            $table->dateTime('pev_recibido_en', 6);

            $table->unique('pev_event_uuid', 'uq_pev_event_uuid');
            $table->index(['pev_entrega_id', 'pev_tipo', 'pev_ocurrido_en'], 'idx_pev_entrega_tipo_fecha');
            $table->index(['pev_tipo', 'pev_ocurrido_en'], 'idx_pev_tipo_fecha');
            $table->foreign('pev_entrega_id', 'fk_pev_entrega')->references('pen_id')->on('stj_push_entregas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_push_eventos');
    }
};
