<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CalculateBestSellingProducts extends Command
{
    protected $signature = 'productos:calcular-mas-vendidos';

    protected $description = 'Alias compatible para calcular las metricas de productos';

    public function handle(): int
    {
        $this->warn('Este comando es un alias de productos:calcular-metricas.');

        return $this->call('productos:calcular-metricas');
    }
}
