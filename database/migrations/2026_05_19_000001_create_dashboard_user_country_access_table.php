<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_user_country_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cas_user_id');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('country_id');
            $table->string('country_code', 5);
            $table->string('country_name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['cas_user_id', 'country_id'], 'dashboard_user_country_unique');
            $table->index(['cas_user_id', 'is_default'], 'dashboard_user_country_default_index');
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_user_country_access');
    }
};
