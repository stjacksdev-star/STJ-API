<?php

namespace App\Console\Commands;

use App\Services\InventoryReport\InventoryReportRunStarter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class StartInventoryReport extends Command
{
    protected $signature = 'inventory-report:start
        {--date= : Fecha logica YYYY-MM-DD; por defecto hoy en la zona del reporte}
        {--country=* : Pais especifico; puede repetirse}
        {--dry-run : Cuenta productos sin crear corridas ni snapshots}';

    protected $description = 'Crea de forma idempotente las corridas y snapshots del reporte diario de inventario';

    public function handle(InventoryReportRunStarter $starter): int
    {
        try {
            $date = $this->reportDate();
            $countries = $this->countries();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($countries === []) {
            $this->warn('No hay paises activos configurados para iniciar el reporte.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($countries as $country) {
            try {
                $summary = $starter->start($country, $date, (bool) $this->option('dry-run'));
                $state = $summary['dry_run'] ? 'VISTA PREVIA' : ($summary['created'] ? 'CREADA' : 'YA EXISTIA');
                $run = $summary['run_id'] === null ? '' : " | Corrida: {$summary['run_id']}";
                $this->line("{$summary['country_code']} | {$state}{$run} | Productos: {$summary['products']}");
            } catch (Throwable $exception) {
                report($exception);
                $this->error("{$country} | {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function reportDate(): Carbon
    {
        $timezone = (string) config('inventory_report.timezone', 'America/El_Salvador');
        $value = trim((string) $this->option('date'));
        if ($value === '') {
            return Carbon::now($timezone)->startOfDay();
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException('--date debe tener formato YYYY-MM-DD y ser una fecha valida.');
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('--date debe tener formato YYYY-MM-DD y ser una fecha valida.');
        }

        return $date;
    }

    /** @return array<int, string> */
    private function countries(): array
    {
        $configured = array_keys((array) config('inventory_report.countries', []));
        $requested = array_values(array_unique(array_filter(array_map(
            static fn (mixed $country): string => strtoupper(trim((string) $country)),
            (array) $this->option('country'),
        ))));

        if ($requested === []) {
            $active = DB::table('stj_paises')
                ->where('pai_estado', 'ACTIVO')
                ->whereIn('pai_codigo', $configured)
                ->get(['pai_id', 'pai_codigo'])
                ->filter(function (object $country): bool {
                    $code = strtoupper(trim((string) $country->pai_codigo));

                    return (int) $country->pai_id === (int) config("inventory_report.countries.{$code}.id");
                })
                ->map(static fn (object $country): string => strtoupper(trim((string) $country->pai_codigo)))
                ->all();

            return array_values(array_intersect($configured, $active));
        }

        $unsupported = array_values(array_diff($requested, $configured));
        if ($unsupported !== []) {
            throw new InvalidArgumentException('Paises no soportados: '.implode(', ', $unsupported).'.');
        }

        return $requested;
    }
}
