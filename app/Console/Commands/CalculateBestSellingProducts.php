<?php

namespace App\Console\Commands;

use App\Services\ProductBestSellerCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateBestSellingProducts extends Command
{
    protected $signature = 'productos:calcular-mas-vendidos';

    protected $description = 'Calcula y persiste el ranking de productos más vendidos por país y período';

    public function handle(ProductBestSellerCalculator $calculator): int
    {
        $errors = 0;

        foreach (ProductBestSellerCalculator::COUNTRIES as $countryId) {
            foreach (ProductBestSellerCalculator::PERIODS as $days) {
                $this->line("Procesando país {$countryId}, período {$days} días...");

                try {
                    $count = $calculator->calculateAndStore($countryId, $days);
                    $this->info("País {$countryId} | período {$days} días | productos encontrados: {$count}");
                    Log::info('Ranking de productos más vendidos calculado.', [
                        'country_id' => $countryId,
                        'period_days' => $days,
                        'products' => $count,
                    ]);
                } catch (Throwable $exception) {
                    $errors++;
                    $this->error("Error en país {$countryId}, período {$days} días: {$exception->getMessage()}");
                    Log::error('Error calculando productos más vendidos.', [
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

        $this->info('Todos los rankings fueron calculados correctamente.');

        return self::SUCCESS;
    }
}
