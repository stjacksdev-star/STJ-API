<?php

namespace App\Console\Commands;

use App\Services\StorefrontOrderConfirmationEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryStorefrontOrderConfirmation extends Command
{
    protected $signature = 'storefront:retry-order-confirmation {references* : Referencias STJ de los pedidos}';

    protected $description = 'Reenvía confirmaciones pendientes de pedidos aprobados por referencia STJ';

    public function handle(StorefrontOrderConfirmationEmailService $email): int
    {
        $failed = false;

        foreach (array_unique($this->argument('references')) as $reference) {
            $order = DB::table('stj_pedidos as orders')
                ->join('stj_pedidos_pago as payment', 'payment.ppa_pedido', '=', 'orders.ped_id')
                ->where('payment.ppa_ref', $reference)
                ->where('payment.ppa_estado', 'APROBADA')
                ->select('orders.ped_id', 'orders.ped_correo_enviado', 'payment.ppa_id')
                ->orderByDesc('payment.ppa_id')
                ->first();

            if (! $order) {
                $this->warn("{$reference}: no se encontró un pago aprobado.");
                $failed = true;
                continue;
            }
            if ($order->ped_correo_enviado !== 'NO') {
                $this->line("{$reference}: confirmación ya marcada como enviada; se omite.");
                continue;
            }

            try {
                $email->send((int) $order->ped_id, (int) $order->ppa_id);
                $this->info("{$reference}: confirmación enviada.");
            } catch (\Throwable $exception) {
                $this->error("{$reference}: {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
