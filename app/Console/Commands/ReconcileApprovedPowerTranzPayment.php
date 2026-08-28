<?php

namespace App\Console\Commands;

use App\Services\StorefrontPaymentEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReconcileApprovedPowerTranzPayment extends Command
{
    protected $signature = 'powertranz:reconcile-approved
        {order : ID del pedido}
        {payment : ID de stj_pedidos_pago}
        {reference : Referencia exacta ppa_ref}
        {--confirm-gateway-approved : Confirma que el operador verificó la aprobación directamente en PowerTranz}';

    protected $description = 'Reconcilia localmente un pago que PowerTranz aprobó pero cuyo retorno falló después de la confirmación';

    public function handle(StorefrontPaymentEventService $events): int
    {
        if (! $this->option('confirm-gateway-approved')) {
            $this->error('Debes verificar primero la aprobación en PowerTranz y usar --confirm-gateway-approved.');

            return self::FAILURE;
        }

        $orderId = (int) $this->argument('order');
        $paymentId = (int) $this->argument('payment');
        $reference = trim((string) $this->argument('reference'));

        try {
            DB::transaction(function () use ($events, $orderId, $paymentId, $reference) {
                $order = DB::table('stj_pedidos')->where('ped_id', $orderId)->lockForUpdate()->first();
                $payment = DB::table('stj_pedidos_pago')->where('ppa_id', $paymentId)->lockForUpdate()->first();
                $operation = DB::table('stj_powertranz_operaciones')->where('pto_pago_id', $paymentId)->lockForUpdate()->latest('pto_id')->first();

                if (! $order || ! $payment || ! $operation) {
                    throw new RuntimeException('No se encontró el pedido, pago u operación PowerTranz indicados.');
                }
                if ((int) $payment->ppa_pedido !== $orderId || ! hash_equals((string) $payment->ppa_ref, $reference)) {
                    throw new RuntimeException('El pago no pertenece al pedido o la referencia no coincide exactamente.');
                }
                if ((string) $payment->ppa_estado === 'APROBADA' && (string) $order->ped_estatus === 'RECIBIDO') {
                    return;
                }
                if ((string) $payment->ppa_estado !== 'PENDIENTE' || (string) $order->ped_estatus !== 'PENDIENTE_PAGO') {
                    throw new RuntimeException('Solo se puede reconciliar un pedido y pago que continúen pendientes.');
                }

                $result = ['status' => 'APROBADA', 'orderId' => $orderId, 'paymentId' => $paymentId, 'reference' => $reference];
                DB::table('stj_powertranz_operaciones')->where('pto_id', $operation->pto_id)->update([
                    'pto_estado' => 'APROBADA',
                    'pto_respuesta_segura' => Crypt::encryptString(json_encode($result, JSON_UNESCAPED_SLASHES)),
                    'pto_actualizado_en' => now(),
                ]);
                DB::table('stj_pedidos_pago')->where('ppa_id', $paymentId)->update([
                    'ppa_rsp_codigo' => '00',
                    'ppa_rsp_mensaje' => 'Transaction is approved (reconciled)',
                    'ppa_fecha_procesado' => now(),
                ]);
                $events->record($paymentId, 'APROBADA', (string) Str::uuid(), [
                    'provider' => 'POWERTRANZ',
                    'reconciled' => true,
                    'reason' => 'Gateway approved; local return rolled back by a non-critical side effect.',
                ]);
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Pedido {$orderId} y pago {$paymentId} reconciliados como APROBADA.");

        return self::SUCCESS;
    }
}
