<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventorySynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncInventory extends Command
{
    protected $signature = 'inventory:sync
        {--country= : Codigo opcional del pais, por ejemplo SV}
        {--batch-size= : Sobrescribe temporalmente el lote configurado (1-500)}
        {--dry-run : Muestra el siguiente lote sin consultar la API ni escribir datos}';

    protected $description = 'Sincroniza por lotes el inventario local desde el endpoint externo';

    public function handle(InventorySynchronizer $synchronizer): int
    {
        $batchSize = $this->batchSize();
        if ($batchSize === false) {
            return self::FAILURE;
        }

        $lock = Cache::lock('inventory-sync-command', 15 * 60);
        if (! $lock->get()) {
            $this->warn('Ya existe una sincronizacion de inventario en ejecucion.');

            return self::SUCCESS;
        }

        try {
            $summary = $synchronizer->run(
                $this->option('country') ?: null,
                $batchSize,
                (bool) $this->option('dry-run'),
            );
        } finally {
            $lock->release();
        }

        $this->renderSummary($summary);
        Log::log($summary['ok'] ? 'info' : 'error', 'Sincronizacion de inventario procesada.', $summary);

        return $summary['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function batchSize(): int|false|null
    {
        $value = $this->option('batch-size');
        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1 || (int) $value > 500) {
            $this->error('--batch-size debe ser un numero entre 1 y 500.');

            return false;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $summary */
    private function renderSummary(array $summary): void
    {
        if (! isset($summary['countryCode'])) {
            $this->line($summary['message']);

            return;
        }

        $this->line("Pais: {$summary['countryCode']} - {$summary['countryName']}");
        $this->line("Perfil: {$summary['endpointProfile']}");
        $this->line("Cursor inicial: {$summary['cursor']}");
        $this->line("Productos: {$summary['products']} | Tiendas: {$summary['stores']} | Filas recibidas: {$summary['rows']}");
        $this->line('Ciclo completado: '.($summary['cycleCompleted'] ? 'SI' : 'NO'));
        $this->line($summary['message']);

        foreach ($summary['errors'] as $error) {
            $this->error($error);
        }
    }
}
