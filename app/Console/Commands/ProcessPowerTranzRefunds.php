<?php

namespace App\Console\Commands;

use App\Services\Payments\PowerTranzRefundService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ProcessPowerTranzRefunds extends Command
{
    protected $signature = 'powertranz:refund-pending
        {identifier? : Referencia STJ o ped_id; si se omite procesa pendientes automaticamente}
        {--country= : Limita el proceso automatico a un codigo de pais}
        {--limit=100 : Maximo de devoluciones automaticas por ejecucion}';

    protected $description = 'Procesa devoluciones PowerTranz pendientes con ppa_transactionidentifier';

    public function handle(PowerTranzRefundService $refunds): int
    {
        $identifier = trim((string) $this->argument('identifier'));
        if ($identifier !== '') {
            $orderId = $refunds->resolveOrderId($identifier);
            if (! $orderId) {
                $this->error('No se encontro un pedido para el identificador indicado.');
                return self::FAILURE;
            }
            return $this->processOne($refunds, $orderId);
        }

        $orderIds = $refunds->pendingOrderIds($this->option('country') ?: null, (int) $this->option('limit'));
        if ($orderIds === []) {
            $this->line('PowerTranz: no hay devoluciones elegibles con transactionidentifier.');
            return self::SUCCESS;
        }
        $failed = 0;
        foreach ($orderIds as $orderId) {
            $failed += $this->processOne($refunds, $orderId) === self::SUCCESS ? 0 : 1;
        }
        $this->line('PowerTranz: procesadas '.(count($orderIds) - $failed).', pendientes con error '.$failed.'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function processOne(PowerTranzRefundService $refunds, int $orderId): int
    {
        try {
            $result = $refunds->process($orderId);
            if ($result['status'] !== 'APROBADA') {
                $this->error("Pedido {$orderId}: PowerTranz no aprobo la devolucion; permanece pendiente.");
                return self::FAILURE;
            }
            $this->info("Pedido {$orderId}: devolucion APROBADA por {$result['amount']}.");
            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error("Pedido {$orderId}: ".collect($exception->errors())->flatten()->first());
            return self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error("Pedido {$orderId}: fallo interno; permanece pendiente.");
            return self::FAILURE;
        }
    }
}
