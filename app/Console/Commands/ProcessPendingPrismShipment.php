<?php

namespace App\Console\Commands;

use App\Services\Prism\PrismShipmentProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessPendingPrismShipment extends Command
{
    protected $signature = 'prism:process-pending';

    protected $description = 'Procesa como máximo un envío pendiente de Retail Prism Honduras';

    public function handle(PrismShipmentProcessor $processor): int
    {
        if (! config('prism.hn.process_pending_enabled')) {
            $this->line('Prism HN: procesamiento automático desactivado.');

            return self::SUCCESS;
        }

        if (! config('storefront_post_purchase.integrations_enabled')
            || ! config('storefront_post_purchase.honduras.enabled')) {
            $this->error('Prism HN: los interruptores de integraciones externas no están activos.');

            return self::FAILURE;
        }

        $maxAttempts = max(1, (int) config('prism.hn.max_attempts'));
        $retryBefore = now()->subMinutes(max(1, (int) config('prism.hn.retry_minutes')));

        // Hard limit: exactly zero or one shipment per command execution.
        // Rows left in "procesando" are intentionally excluded and require review.
        $shipment = DB::table('prism_envios as e')
            ->join('stj_paises as p', 'p.pai_id', '=', 'e.pais_codigo')
            ->where('p.pai_codigo', 'HN')
            ->where('e.integration_environment', app()->environment())
            ->where('e.intentos', '<', $maxAttempts)
            ->where(function ($query) use ($retryBefore) {
                $query->where('e.status', 'pendiente')
                    ->orWhere(function ($retry) use ($retryBefore) {
                        $retry->where('e.status', 'error')
                            ->where(function ($age) use ($retryBefore) {
                                $age->whereNull('e.last_try_at')->orWhere('e.last_try_at', '<=', $retryBefore);
                            });
                    });
            })
            ->orderByRaw("CASE WHEN e.status = 'pendiente' THEN 0 ELSE 1 END")
            ->orderBy('e.created_at')
            ->orderBy('e.pe_id')
            ->first(['e.pe_id', 'e.stj_ref']);

        if (! $shipment) {
            $this->line('Prism HN: no hay envíos elegibles.');

            return self::SUCCESS;
        }

        try {
            $result = $processor->process((int) $shipment->pe_id, true);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error(get_class($exception) === RuntimeException::class
                ? $exception->getMessage()
                : 'Fallo interno del procesador Prism; revisar envío y logs.');

            return self::FAILURE;
        }
    }
}
