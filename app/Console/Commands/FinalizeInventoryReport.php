<?php

namespace App\Console\Commands;

use App\Services\InventoryReport\InventoryReportRunFinalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class FinalizeInventoryReport extends Command
{
    protected $signature = 'inventory-report:finalize
        {--date= : Fecha logica YYYY-MM-DD; por defecto hoy}';

    protected $description = 'Cierra las corridas abiertas al finalizar la ventana nocturna del reporte';

    public function handle(InventoryReportRunFinalizer $finalizer): int
    {
        try {
            $date = $this->date();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $lock = Cache::lock('inventory-report-process', 15 * 60);
        if (! $lock->get()) {
            $this->warn('Hay un lote de inventario en procesamiento; el cierre se reintentara en la siguiente ejecucion.');

            return self::SUCCESS;
        }

        try {
            $summary = $finalizer->finalize($date);
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        if ($summary['finalized'] === 0) {
            $this->line("No hay corridas abiertas para {$date}.");

            return self::SUCCESS;
        }
        foreach ($summary['runs'] as $run) {
            $this->line("{$run['countryCode']} | {$run['status']} | Encontrados: {$run['found']} | No devueltos: {$run['notReturned']} | Fallidos: {$run['failed']} | Filas: {$run['rows']}");
        }

        return self::SUCCESS;
    }

    private function date(): string
    {
        $timezone = (string) config('inventory_report.timezone', 'America/El_Salvador');
        $value = trim((string) $this->option('date'));
        if ($value === '') {
            return Carbon::now($timezone)->toDateString();
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException('--date debe tener formato YYYY-MM-DD y ser una fecha valida.');
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('--date debe tener formato YYYY-MM-DD y ser una fecha valida.');
        }

        return $value;
    }
}
