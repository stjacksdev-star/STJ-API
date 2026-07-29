<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('stj_promociones')
            && ! Schema::hasColumn('stj_promociones', 'prm_alcance_tienda')
        ) {
            Schema::table('stj_promociones', function (Blueprint $table) {
                $table->enum('prm_alcance_tienda', ['TODAS', 'SELECCIONADAS'])
                    ->nullable()
                    ->after('prm_tipo_checkout');
            });
        }

        if (
            ! Schema::hasTable('stj_promociones_tienda')
            && Schema::hasTable('stj_promociones')
            && Schema::hasTable('stj_tiendas')
        ) {
            Schema::create('stj_promociones_tienda', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';

                $table->bigInteger('prt_id', true);
                $table->bigInteger('prt_promocion');
                $table->bigInteger('prt_tienda');
                $table->dateTime('prt_fecha_creacion')->useCurrent();

                $table->unique(['prt_promocion', 'prt_tienda'], 'uk_promocion_tienda');
                $table->index('prt_tienda', 'idx_prt_tienda');
                $table->foreign('prt_promocion', 'fk_prt_promocion')
                    ->references('prm_id')
                    ->on('stj_promociones')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->foreign('prt_tienda', 'fk_prt_tienda')
                    ->references('tie_id')
                    ->on('stj_tiendas')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });

            return;
        }

        if (! Schema::hasTable('stj_promociones_tienda')) {
            return;
        }

        Schema::table('stj_promociones_tienda', function (Blueprint $table) {
            if (! Schema::hasColumn('stj_promociones_tienda', 'prt_id')) {
                $table->bigInteger('prt_id', true);
            }
            if (! Schema::hasColumn('stj_promociones_tienda', 'prt_promocion')) {
                $table->bigInteger('prt_promocion');
            }
            if (! Schema::hasColumn('stj_promociones_tienda', 'prt_tienda')) {
                $table->bigInteger('prt_tienda');
            }
            if (! Schema::hasColumn('stj_promociones_tienda', 'prt_fecha_creacion')) {
                $table->dateTime('prt_fecha_creacion')->useCurrent();
            }
        });

        if (! Schema::hasIndex('stj_promociones_tienda', 'uk_promocion_tienda')) {
            Schema::table('stj_promociones_tienda', function (Blueprint $table) {
                $table->unique(['prt_promocion', 'prt_tienda'], 'uk_promocion_tienda');
            });
        }
        if (! Schema::hasIndex('stj_promociones_tienda', 'idx_prt_tienda')) {
            Schema::table('stj_promociones_tienda', function (Blueprint $table) {
                $table->index('prt_tienda', 'idx_prt_tienda');
            });
        }

        if (
            Schema::hasTable('stj_promociones')
            && ! $this->hasForeignKey('stj_promociones_tienda', 'fk_prt_promocion')
        ) {
            Schema::table('stj_promociones_tienda', function (Blueprint $table) {
                $table->foreign('prt_promocion', 'fk_prt_promocion')
                    ->references('prm_id')
                    ->on('stj_promociones')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }
        if (
            Schema::hasTable('stj_tiendas')
            && ! $this->hasForeignKey('stj_promociones_tienda', 'fk_prt_tienda')
        ) {
            Schema::table('stj_promociones_tienda', function (Blueprint $table) {
                $table->foreign('prt_tienda', 'fk_prt_tienda')
                    ->references('tie_id')
                    ->on('stj_tiendas')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: both structures may predate this migration.
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreign) => ($foreign['name'] ?? null) === $name);
    }
};
