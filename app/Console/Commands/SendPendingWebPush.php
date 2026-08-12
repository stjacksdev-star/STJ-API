<?php

namespace App\Console\Commands;

use App\Services\WebPushDeliveryProcessor;
use Illuminate\Console\Command;
use Throwable;

class SendPendingWebPush extends Command
{
    protected $signature = 'push:web-send-pending
        {--delivery= : Procesa solamente este pen_id si esta disponible}
        {--limit=100 : Maximo de entregas por ejecucion}';

    protected $description = 'Revalida y envia entregas pendientes de la outbox Push Web';

    public function handle(WebPushDeliveryProcessor $processor): int
    {
        try {
            $deliveryId = filled($this->option('delivery')) ? (int) $this->option('delivery') : null;
            if ($deliveryId !== null && $deliveryId < 1) {
                $this->error('--delivery debe ser un pen_id positivo.');

                return self::INVALID;
            }

            $summary = $processor->process($deliveryId, (int) $this->option('limit'));
        } catch (Throwable $exception) {
            $this->error('No fue posible procesar las entregas Push Web: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Resultado', 'Cantidad'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values()->all());

        if ($deliveryId !== null && $summary['pending'] === 0) {
            $this->warn("La entrega #{$deliveryId} no existe, no esta pendiente o aun no esta disponible.");
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
