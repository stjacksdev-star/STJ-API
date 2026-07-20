<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stj_powertranz_operaciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('pto_id', true);
            $table->char('pto_uuid', 36)->unique('uq_pto_uuid');
            $table->bigInteger('pto_pago_id');
            $table->char('pto_return_token_hash', 64)->unique('uq_pto_return_hash');
            $table->char('pto_payload_hash', 64);
            $table->string('pto_estado', 20)->default('INICIADA');
            $table->text('pto_respuesta_segura')->nullable();
            $table->dateTime('pto_creado_en', 6);
            $table->dateTime('pto_actualizado_en', 6);
            $table->foreign('pto_pago_id', 'fk_pto_pago')->references('ppa_id')->on('stj_pedidos_pago');
            $table->index(['pto_pago_id', 'pto_estado'], 'idx_pto_pago_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_powertranz_operaciones');
    }
};
