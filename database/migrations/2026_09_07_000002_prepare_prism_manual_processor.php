<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prism_envios', 'processing_checkpoint')) {
            Schema::table('prism_envios', fn (Blueprint $table) => $table->longText('processing_checkpoint')->nullable());
        }
        if (DB::connection()->getDriverName() === 'mysql' && Schema::getColumnType('prism_envios_log', 'step') === 'enum') {
            DB::statement('ALTER TABLE prism_envios_log MODIFY COLUMN step VARCHAR(80) NOT NULL');
        }
    }

    public function down(): void
    {
        // Checkpoints and new log step names must survive rollback of application code.
    }
};
