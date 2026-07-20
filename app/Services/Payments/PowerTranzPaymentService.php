<?php

namespace App\Services\Payments;

use App\Exceptions\CartOperationConflict;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontPaymentEventService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PowerTranzPaymentService
{
    public function __construct(private PowerTranzConfigResolver $configuration, private PowerTranzPayloadFactory $payloads, private PowerTranzClient $client, private StorefrontPaymentEventService $events) {}

    public function start(int $orderId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($orderId, $visitor, $customer, $input) {
            $order = DB::table('stj_pedidos')->where('ped_id', $orderId)->lockForUpdate()->first();
            $cart = DB::table('stj_carritos')->where('car_pedido_id', $orderId)->lockForUpdate()->first();
            if (! $order || ! $cart || $cart->car_estado !== 'CONVERTIDO' || (int) $cart->car_visitante_id !== $visitor->getKey() || ($customer && (int) $cart->car_usu_id !== $customer->getKey()) || (! $customer && $cart->car_usu_id !== null)) {
                throw ValidationException::withMessages(['order' => 'Pedido no encontrado para la identidad autorizada.']);
            }
            if ($order->ped_estatus !== 'PENDIENTE_PAGO') {
                throw ValidationException::withMessages(['order' => 'El pedido no esta pendiente de pago.']);
            }
            $payment = DB::table('stj_pedidos_pago')->where('ppa_pedido', $orderId)->orderByDesc('ppa_id')->lockForUpdate()->first();
            if (! $payment || $payment->ppa_estado === 'APROBADA' || ! in_array($payment->ppa_estado, ['PENDIENTE', 'DENEGADA', 'TIMEOUT', 'ERROR'], true)) {
                throw ValidationException::withMessages(['payment' => 'El intento de pago no puede iniciarse o ya fue aprobado.']);
            }
            if ($order->ped_checkout === 'DOMICILIO') {
                $shipping = DB::table('stj_pedidos_direccion')->where('pdi_pedido', $orderId)->first();
                if (! $shipping || str_contains(strtolower((string) $shipping->pdi_costo_envio_txt), 'pendiente')) {
                    throw ValidationException::withMessages(['shipping' => 'El costo de envio debe calcularse antes de iniciar PowerTranz.']);
                }
            }
            $country = strtolower((string) DB::table('stj_paises')->where('pai_id', $order->ped_id_pais)->value('pai_codigo'));
            $configuration = $this->configuration->forCountry($country);
            $pan = preg_replace('/\D+/', '', (string) $input['card']['pan']);
            $fingerprint = hash_hmac('sha256', json_encode(['order' => $orderId, 'payment' => $payment->ppa_id, 'cardLast4' => substr($pan, -4), 'expiration' => $input['card']['expiration'], 'holder' => trim((string) $input['card']['holder'])], JSON_UNESCAPED_SLASHES), (string) config('app.key'));
            $existing = DB::table('stj_powertranz_operaciones')->where('pto_uuid', $input['operation_uuid'])->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->pto_pago_id !== (int) $payment->ppa_id || ! hash_equals($existing->pto_payload_hash, $fingerprint)) {
                    throw new CartOperationConflict('operation_uuid ya fue utilizado con otro contenido.');
                }

                return json_decode(Crypt::decryptString($existing->pto_respuesta_segura), true);
            }
            $returnToken = Str::random(64);
            $returnUrl = route('powertranz.return', ['country' => $country, 'token' => $returnToken]);
            $payload = $this->payloads->sale($order, $payment, $input['card'], $configuration['currency'], $input['operation_uuid'], $returnUrl);
            $gateway = $this->client->sale($configuration, $payload, $input['operation_uuid']);
            $result = $this->safeInitialResult($gateway, $payment, $country, $input['operation_uuid']);
            DB::table('stj_powertranz_operaciones')->insert(['pto_uuid' => $input['operation_uuid'], 'pto_pago_id' => $payment->ppa_id, 'pto_return_token_hash' => hash('sha256', $returnToken), 'pto_payload_hash' => $fingerprint, 'pto_estado' => $result['status'], 'pto_respuesta_segura' => Crypt::encryptString(json_encode($result, JSON_UNESCAPED_SLASHES)), 'pto_creado_en' => now(), 'pto_actualizado_en' => now()]);
            DB::table('stj_pedidos_pago')->where('ppa_id', $payment->ppa_id)->update(['ppa_transactionidentifier' => $input['operation_uuid'], 'ppa_estado' => $result['status'] === 'DENEGADA' ? 'DENEGADA' : 'PENDIENTE', 'ppa_fecha_procesado' => now()]);
            if ($result['status'] === 'APROBADA') {
                $this->events->record((int) $payment->ppa_id, 'APROBADA', (string) Str::uuid(), ['provider' => 'POWERTRANZ']);
            }

            return $result;
        });
    }

    public function confirm(string $country, string $token, array $input): array
    {
        return DB::transaction(function () use ($country, $token, $input) {
            $operation = DB::table('stj_powertranz_operaciones')->where('pto_return_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $operation) {
                throw ValidationException::withMessages(['return' => 'Retorno PowerTranz desconocido.']);
            }
            if (in_array($operation->pto_estado, ['APROBADA', 'DENEGADA'], true)) {
                return json_decode(Crypt::decryptString($operation->pto_respuesta_segura), true);
            }
            $payment = DB::table('stj_pedidos_pago')->where('ppa_id', $operation->pto_pago_id)->first();
            $order = DB::table('stj_pedidos')->where('ped_id', $payment->ppa_pedido)->first();
            $actualCountry = strtolower((string) DB::table('stj_paises')->where('pai_id', $order->ped_id_pais)->value('pai_codigo'));
            if ($actualCountry !== strtolower($country)) {
                throw ValidationException::withMessages(['country' => 'El pais del retorno no coincide.']);
            }
            $configuration = $this->configuration->forCountry($actualCountry);
            if ((string) $input['TransactionIdentifier'] !== (string) $operation->pto_uuid) {
                throw ValidationException::withMessages(['return' => 'El identificador de transaccion no coincide.']);
            }
            $gateway = $this->client->confirm($configuration, (string) $input['SpiToken'], (string) $operation->pto_uuid);
            $this->verifyConfirmation($gateway, $payment, $configuration['currency']);
            $status = ($gateway['Approved'] ?? false) === true ? 'APROBADA' : 'DENEGADA';
            $result = ['status' => $status, 'orderId' => (int) $order->ped_id, 'paymentId' => (int) $payment->ppa_id, 'reference' => (string) $payment->ppa_ref];
            DB::table('stj_powertranz_operaciones')->where('pto_id', $operation->pto_id)->update(['pto_estado' => $status, 'pto_respuesta_segura' => Crypt::encryptString(json_encode($result)), 'pto_actualizado_en' => now()]);
            $this->events->record((int) $payment->ppa_id, $status, (string) Str::uuid(), ['provider' => 'POWERTRANZ']);

            return $result;
        });
    }

    private function safeInitialResult(array $response, object $payment, string $country, string $transaction): array
    {
        if (($response['Approved'] ?? false) === true) {
            return ['status' => 'APROBADA', 'orderId' => (int) $payment->ppa_pedido, 'paymentId' => (int) $payment->ppa_id, 'reference' => (string) $payment->ppa_ref, 'transactionIdentifier' => $transaction];
        }
        if (filled($response['RedirectData'] ?? null)) {
            return ['status' => 'PENDIENTE', 'orderId' => (int) $payment->ppa_pedido, 'paymentId' => (int) $payment->ppa_id, 'reference' => (string) $payment->ppa_ref, 'country' => $country, 'transactionIdentifier' => $transaction, 'redirectData' => (string) $response['RedirectData']];
        }

        return ['status' => 'DENEGADA', 'orderId' => (int) $payment->ppa_pedido, 'paymentId' => (int) $payment->ppa_id, 'reference' => (string) $payment->ppa_ref, 'code' => (string) ($response['IsoResponseCode'] ?? '')];
    }

    private function verifyConfirmation(array $response, object $payment, string $currency): void
    {
        if (isset($response['OrderIdentifier']) && (string) $response['OrderIdentifier'] !== (string) $payment->ppa_ref) {
            throw ValidationException::withMessages(['return' => 'La referencia confirmada no coincide.']);
        }
        if (isset($response['CurrencyCode']) && (string) $response['CurrencyCode'] !== $currency) {
            throw ValidationException::withMessages(['return' => 'La moneda confirmada no coincide.']);
        }
        if (isset($response['TotalAmount']) && $this->cents((string) $response['TotalAmount']) !== $this->cents((string) $payment->ppa_monto)) {
            throw ValidationException::withMessages(['return' => 'El importe confirmado no coincide.']);
        }
    }

    private function cents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', trim($value)), 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
