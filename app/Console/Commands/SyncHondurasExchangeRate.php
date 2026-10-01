<?php

namespace App\Console\Commands;

use App\Services\HondurasExchangeRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncHondurasExchangeRate extends Command
{
    protected $signature = 'exchange-rate:sync-hnl-usd';

    protected $description = 'Consulta y guarda la tasa diaria de conversion de HNL a USD';

    public function handle(HondurasExchangeRateService $exchangeRate): int
    {
        if (! config('hn_exchange_rate.enabled')) {
            $this->warn('La sincronizacion de tasa HNL/USD esta deshabilitada.');

            return self::SUCCESS;
        }

        try {
            $result = $exchangeRate->sync();
            $action = $result['created'] ? 'guardada' : 'actualizada';
            $this->info("Tasa HNL/USD del {$result['date']} {$action}: {$result['rate']}");
            Log::info('Tasa HNL/USD sincronizada.', $result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('No fue posible sincronizar la tasa HNL/USD: '.$exception->getMessage());
            Log::error('Fallo al sincronizar la tasa HNL/USD.', ['exception' => $exception]);

            return self::FAILURE;
        }
    }
}
