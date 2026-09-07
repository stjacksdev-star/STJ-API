<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prism_envios', 'integration_environment')) {
            Schema::table('prism_envios', function (Blueprint $table) {
                $table->string('integration_environment', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('prism_envios', function (Blueprint $table) {
            $table->dropColumn('integration_environment');
        });
    }
};
