<?php

namespace App\Console\Commands;

use App\Services\InventoryReport\InventoryReportExcelGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class GenerateInventoryReportExcel extends Command
{
    protected $signature = 'inventory-report:excel
        {--date= : Fecha logica YYYY-MM-DD; por defecto hoy}
        {--country=* : Pais especifico; puede repetirse}
        {--force : Regenera aunque el archivo vigente conserve su hash}';

    protected $description = 'Genera los Excel comerciales de corridas cerradas del reporte de inventario';

    public function handle(InventoryReportExcelGenerator $generator): int
    {
        try {
            $date = $this->date();
            $countries = $this->countries();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $runs = DB::table('stj_inventory_report_runs')
            ->whereDate('irr_report_date', $date->toDateString())
            ->whereIn('irr_status', ['COMPLETE', 'PARTIAL'])
            ->when($countries !== [], fn ($query) => $query->whereIn('irr_country_code', $countries))
            ->orderBy('irr_country_id')
            ->get(['irr_id']);
        if ($runs->isEmpty()) {
            $this->warn("No hay corridas cerradas para {$date->toDateString()}.");

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($runs as $run) {
            try {
                $summary = $generator->generate((int) $run->irr_id, (bool) $this->option('force'));
                $action = $summary['generated'] ? 'GENERADO' : 'VIGENTE';
                $this->line("{$summary['countryCode']} | {$action} | Filas: {$summary['rows']} | {$summary['path']}");
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Corrida {$run->irr_id} | {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function date(): Carbon
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
        $countries = array_values(array_unique(array_filter(array_map(
            static fn (mixed $country): string => strtoupper(trim((string) $country)),
            (array) $this->option('country'),
        ))));
        $unsupported = array_values(array_diff($countries, $configured));
        if ($unsupported !== []) {
            throw new InvalidArgumentException('Paises no soportados: '.implode(', ', $unsupported).'.');
        }

        return $countries;
    }
}
