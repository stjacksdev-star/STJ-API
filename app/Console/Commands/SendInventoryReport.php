<?php

namespace App\Console\Commands;

use App\Services\InventoryReport\InventoryReportEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class SendInventoryReport extends Command
{
    protected $signature = 'inventory-report:send
        {--date= : Fecha logica YYYY-MM-DD; por defecto hoy}
        {--force : Crea un nuevo intento aunque ya exista un envio exitoso o pendiente}';

    protected $description = 'Envia por correo los Excel y la comparativa del reporte diario de inventario';

    public function handle(InventoryReportEmailService $report): int
    {
        try {
            $date = $this->date();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $lock = Cache::lock("inventory-report-send:{$date}", 15 * 60);
        if (! $lock->get()) {
            $this->warn("Ya existe un envio del reporte {$date} en ejecucion.");

            return self::SUCCESS;
        }

        try {
            $summary = $report->send($date, (bool) $this->option('force'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        if ($summary['alreadySent']) {
            $this->warn("El reporte {$date} ya fue enviado en el intento {$summary['attempt']}.");

            return self::SUCCESS;
        }

        $this->info("Reporte {$date} enviado correctamente.");
        $this->line("Entrega: {$summary['deliveryId']} | Intento: {$summary['attempt']} | Adjuntos: {$summary['attachments']}");

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
