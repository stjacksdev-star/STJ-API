<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stj_storefront_password_resets')) return;

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                CREATE TABLE stj_storefront_password_resets (
                    spr_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    spr_email VARCHAR(150) NOT NULL,
                    spr_token_hash CHAR(64) NOT NULL,
                    spr_expires_at TIMESTAMP NOT NULL,
                    spr_used_at TIMESTAMP NULL,
                    spr_request_ip VARCHAR(45) NULL,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL,
                    PRIMARY KEY (spr_id),
                    UNIQUE KEY uq_spr_token_hash (spr_token_hash),
                    KEY idx_spr_email (spr_email),
                    KEY idx_spr_expires_at (spr_expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL);
            return;
        }

        Schema::create('stj_storefront_password_resets', function (Blueprint $table) {
            $table->bigIncrements('spr_id');
            $table->string('spr_email', 150)->index('idx_spr_email');
            $table->char('spr_token_hash', 64)->unique('uq_spr_token_hash');
            $table->timestamp('spr_expires_at')->index('idx_spr_expires_at');
            $table->timestamp('spr_used_at')->nullable();
            $table->string('spr_request_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stj_storefront_password_resets');
    }
};
