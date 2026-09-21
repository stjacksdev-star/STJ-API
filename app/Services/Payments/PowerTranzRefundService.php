<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PowerTranzRefundService
{
    public function __construct(
        private readonly PowerTranzConfigResolver $configuration,
        private readonly PowerTranzClient $client,
    ) {}

    /** @return array<string, mixed> */
    public function process(int $orderId): array
    {
        return DB::transaction(function () use ($orderId): array {
            $order = DB::table('stj_pedidos')->where('ped_id', $orderId)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['order' => 'Pedido no encontrado.']);
            }
            if (strtoupper((string) $order->ped_devolucion_realizada) !== 'NO'
                || round((float) $order->ped_monto_devolucion, 2) <= 0) {
                throw ValidationException::withMessages(['refund' => 'El pedido no tiene una devolucion pendiente.']);
            }

            $payments = DB::table('stj_pedidos_pago')->where('ppa_pedido', $orderId)
                ->where('ppa_estado', 'APROBADA')->orderByDesc('ppa_id')->lockForUpdate()->get();
            $payment = $payments->first(fn ($row) => trim((string) $row->ppa_transactionidentifier) !== '');
            if (! $payment) {
                throw ValidationException::withMessages([
                    'refund' => 'La devolucion no se puede migrar: el pago aprobado no tiene ppa_transactionidentifier.',
                ]);
            }

            $amount = round((float) $order->ped_monto_devolucion, 2);
            if ($amount > round((float) $payment->ppa_monto, 2)) {
                throw ValidationException::withMessages(['refund' => 'El monto a devolver supera el pago aprobado.']);
            }
            $country = strtolower(trim((string) DB::table('stj_paises')
                ->where('pai_id', $order->ped_id_pais)->value('pai_codigo')));
            $configuration = $this->configuration->forCountry($country, (string) $order->ped_origen);
            $transaction = trim((string) $payment->ppa_transactionidentifier);
            $storedResponse = json_decode((string) $order->ped_rsp_servicio, true);
            if (is_array($storedResponse)) {
                unset($storedResponse['LocalValidationError']);
                [$storedApproved] = $this->validateResponse(
                    $storedResponse, $transaction, (string) $payment->ppa_ref, $amount, $configuration['currency'],
                );
                if ($storedApproved) {
                    DB::table('stj_pedidos')->where('ped_id', $orderId)->update([
                        'ped_devolucion_realizada' => 'SI',
                        'ped_rsp_servicio' => json_encode($storedResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'ped_fecha_devolucion_sistema' => now(),
                    ]);

                    return $this->result($orderId, $payment, $country, $order, $amount, 'APROBADA', $storedResponse, true);
                }
            }

            $correlationId = (string) Str::uuid();
            $payload = [
                'Refund' => true,
                'TransactionIdentifier' => $transaction,
                'TotalAmount' => $amount,
                'CurrencyCode' => $configuration['currency'],
                'Source' => ['CardPresent' => false, 'CardEmvFallback' => false, 'ManualEntry' => false,
                    'Debit' => false, 'Contactless' => false, 'CardPan' => '', 'MaskedPan' => ''],
                'TerminalCode' => '',
                'TerminalSerialNumber' => '',
                'ExternalIdentifier' => 'STJ-REFUND-'.$orderId.'-'.(int) round($amount * 100),
                'AddressMatch' => false,
            ];
            $response = $this->client->refund($configuration, $payload, $correlationId);
            [$approved, $validationError] = $this->validateResponse(
                $response, $transaction, (string) $payment->ppa_ref, $amount, $configuration['currency'],
            );
            if ($validationError !== null) {
                $response['LocalValidationError'] = $validationError;
            }

            DB::table('stj_pedidos')->where('ped_id', $orderId)->update([
                'ped_devolucion_realizada' => $approved ? 'SI' : 'NO',
                'ped_rsp_servicio' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ped_fecha_devolucion_sistema' => $approved ? now() : $order->ped_fecha_devolucion_sistema,
            ]);

            return $this->result($orderId, $payment, $country, $order, $amount,
                $approved ? 'APROBADA' : 'RECHAZADA', $response, false);
        }, 3);
    }

    /** @return list<int> */
    public function pendingOrderIds(?string $country = null, int $limit = 100): array
    {
        return DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', fn ($join) => $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                ->where('pay.ppa_estado', 'APROBADA'))
            ->join('stj_paises as country', 'country.pai_id', '=', 'p.ped_id_pais')
            ->where('p.ped_devolucion_realizada', 'NO')->where('p.ped_monto_devolucion', '>', 0)
            ->whereNotNull('pay.ppa_transactionidentifier')->where('pay.ppa_transactionidentifier', '<>', '')
            ->when($country, fn ($query, $value) => $query->where('country.pai_codigo', strtoupper($value)))
            ->orderBy('p.ped_fecha_devolucion')->orderBy('p.ped_id')->limit(max(1, min(500, $limit)))
            ->distinct()->pluck('p.ped_id')->map(fn ($id) => (int) $id)->all();
    }

    public function resolveOrderId(string $identifier): ?int
    {
        $identifier = trim($identifier);
        if (ctype_digit($identifier)) {
            return DB::table('stj_pedidos')->where('ped_id', (int) $identifier)->value('ped_id');
        }

        return DB::table('stj_pedidos_pago')->where('ppa_ref', $identifier)->orderByDesc('ppa_id')->value('ppa_pedido');
    }

    /** @return array{bool, ?string} */
    private function validateResponse(array $response, string $originalTransaction, string $reference, float $amount, string $currency): array
    {
        if (($response['Approved'] ?? null) !== true) {
            return [false, null];
        }
        $returnedOriginal = trim((string) ($response['OriginalTrxnIdentifier']
            ?? $response['OriginalTransactionIdentifier'] ?? ''));
        if ($returnedOriginal === '' || $returnedOriginal !== $originalTransaction) {
            return [false, 'OriginalTrxnIdentifier no coincide con la operacion original.'];
        }
        if (trim((string) ($response['TransactionIdentifier'] ?? '')) === '') {
            return [false, 'PowerTranz no devolvio el identificador de la devolucion.'];
        }
        if (isset($response['OrderIdentifier']) && (string) $response['OrderIdentifier'] !== $reference) {
            return [false, 'OrderIdentifier no coincide con la referencia del pedido.'];
        }
        if (isset($response['CurrencyCode']) && (string) $response['CurrencyCode'] !== $currency) {
            return [false, 'CurrencyCode no coincide con la moneda esperada.'];
        }
        if (isset($response['TotalAmount']) && (int) round((float) $response['TotalAmount'] * 100) !== (int) round($amount * 100)) {
            return [false, 'TotalAmount no coincide con el monto solicitado.'];
        }

        return [true, null];
    }

    /** @return array<string, mixed> */
    private function result(int $orderId, object $payment, string $country, object $order, float $amount,
        string $status, array $response, bool $reconciled): array
    {
        return ['orderId' => $orderId, 'reference' => (string) $payment->ppa_ref,
            'country' => strtoupper($country), 'origin' => strtoupper((string) $order->ped_origen),
            'amount' => $amount, 'status' => $status, 'response' => $response, 'reconciled' => $reconciled];
    }
}
