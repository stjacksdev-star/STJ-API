<?php

namespace App\Console\Commands;

use App\Services\InventoryReport\InventoryReportBatchProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class ProcessInventoryReport extends Command
{
    protected $signature = 'inventory-report:process
        {--date= : Fecha logica YYYY-MM-DD; por defecto cualquier corrida pendiente}
        {--country= : Codigo opcional de pais}
        {--batch-size= : Sobrescribe el lote configurado, entre 1 y 500}';

    protected $description = 'Procesa un lote pendiente del reporte diario de inventario';

    public function handle(InventoryReportBatchProcessor $processor): int
    {
        try {
            $date = $this->date();
            $batchSize = $this->batchSize();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $country = strtoupper(trim((string) $this->option('country'))) ?: null;
        $lock = Cache::lock('inventory-report-process', max(120, (int) config('inventory_report.timeout_seconds', 60) + 60));
        if (! $lock->get()) {
            $this->warn('Ya existe un lote del reporte de inventario en procesamiento.');

            return self::SUCCESS;
        }

        try {
            $summary = $processor->process($country, $date, $batchSize);
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        if (! $summary['processed']) {
            $this->line($summary['message']);

            return self::SUCCESS;
        }

        $this->line("Pais: {$summary['countryCode']} | Corrida: {$summary['runId']} | Solicitud: {$summary['requestId']}");
        $this->line("Productos: {$summary['products']} | Filas: {$summary['rows']} | Encontrados: {$summary['found']}");
        $this->line("Reintento: {$summary['retryPending']} | No devueltos: {$summary['notReturned']} | Fallidos: {$summary['failed']}");
        $this->line("Estado de corrida: {$summary['run']['status']} | Pendientes totales: {$summary['run']['pending']}");
        if (! $summary['ok']) {
            $this->error($summary['error']);
        }

        return $summary['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function date(): ?Carbon
    {
        $value = trim((string) $this->option('date'));
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, (string) config('inventory_report.timezone'));
        } catch (Throwable) {
            throw new InvalidArgumentException('--date debe tener formato YYYY-MM-DD y ser una fecha valida.');
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('--date debe tener formato YYYY-MM-DD y ser una fecha valida.');
        }

        return $date;
    }

    private function batchSize(): ?int
    {
        $value = $this->option('batch-size');
        if ($value === null || $value === '') {
            return null;
        }
        if (! ctype_digit((string) $value) || (int) $value < 1 || (int) $value > 500) {
            throw new InvalidArgumentException('--batch-size debe ser un numero entre 1 y 500.');
        }

        return (int) $value;
    }
}
