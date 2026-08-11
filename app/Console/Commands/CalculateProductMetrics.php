<?php

namespace App\Console\Commands;

use App\Services\ProductMetricsCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateProductMetrics extends Command
{
    protected $signature = 'productos:calcular-metricas';

    protected $description = 'Calcula las metricas de ventas, vistas, favoritos y carrito de productos por pais y periodo';

    public function handle(ProductMetricsCalculator $calculator): int
    {
        $errors = 0;
        foreach (ProductMetricsCalculator::COUNTRIES as $countryId) {
            foreach (ProductMetricsCalculator::PERIODS as $days) {
                $this->line("Procesando pais {$countryId}, periodo {$days} dias...");
                try {
                    $count = $calculator->calculateAndStore($countryId, $days);
                    $this->info("Pais {$countryId} | periodo {$days} dias | productos encontrados: {$count}");
                    Log::info('Metricas de productos calculadas.', compact('countryId', 'days', 'count'));
                } catch (Throwable $exception) {
                    $errors++;
                    $this->error("Error en pais {$countryId}, periodo {$days} dias: {$exception->getMessage()}");
                    Log::error('Error calculando metricas de productos.', [
                        'country_id' => $countryId,
                        'period_days' => $days,
                        'exception' => $exception,
                    ]);
                }
            }
        }

        if ($errors > 0) {
            $this->error("Proceso finalizado con {$errors} error(es).");

            return self::FAILURE;
        }
        $this->info('Todas las metricas fueron calculadas correctamente.');

        return self::SUCCESS;
    }
}
