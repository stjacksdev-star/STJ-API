<?php

namespace App\Services\Payments;

use App\Exceptions\CartOperationConflict;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontPaymentEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PowerTranzPaymentService
{
    public function __construct(private PowerTranzConfigResolver $configuration, private PowerTranzPayloadFactory $payloads, private PowerTranzClient $client, private StorefrontPaymentEventService $events, private ?PowerTranzUrlFactory $urls = null, private ?CardBrandDetector $cardBrands = null) {}

    public function start(int $orderId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($orderId, $visitor, $customer, $input) {
            $order = DB::table('stj_pedidos')->where('ped_id', $orderId)->lockForUpdate()->first();
            $cart = DB::table('stj_carritos')->where('car_pedido_id', $orderId)->lockForUpdate()->first();
            $identityMatches = $customer
                ? (int) $cart?->car_usu_id === $customer->getKey()
                : $cart?->car_usu_id === null && (int) $cart?->car_visitante_id === $visitor->getKey();
            if (! $order || ! $cart || $cart->car_estado !== 'CONVERTIDO' || ! $identityMatches) {
                throw ValidationException::withMessages(['order' => 'Pedido no encontrado para la identidad autorizada.']);
            }
            if ($order->ped_estatus !== 'PENDIENTE_PAGO') {
                throw ValidationException::withMessages(['order' => 'El pedido no esta pendiente de pago.']);
            }
            $payment = DB::table('stj_pedidos_pago')->where('ppa_pedido', $orderId)->orderByDesc('ppa_id')->lockForUpdate()->first();
            if (! $payment || $payment->ppa_estado === 'APROBADA' || ! in_array($payment->ppa_estado, ['PENDIENTE', 'DENEGADA', 'TIMEOUT', 'ERROR'], true)) {
                throw ValidationException::withMessages(['payment' => 'El intento de pago no puede iniciarse o ya fue aprobado.']);
            }
            if (strtoupper((string) $payment->ppa_tipo) !== 'TARJETA') {
                throw ValidationException::withMessages(['payment' => 'PowerTranz solo puede iniciarse para pagos con tarjeta.']);
            }
            if ($order->ped_checkout === 'DOMICILIO') {
                $shipping = DB::table('stj_pedidos_direccion')->where('pdi_pedido', $orderId)->first();
                if (! $shipping || str_contains(strtolower((string) $shipping->pdi_costo_envio_txt), 'pendiente')) {
                    throw ValidationException::withMessages(['shipping' => 'El costo de envio debe calcularse antes de iniciar PowerTranz.']);
                }
            }
            $country = strtolower((string) DB::table('stj_paises')->where('pai_id', $order->ped_id_pais)->value('pai_codigo'));
            $configuration = $this->configuration->forCountry($country);
            $fingerprint = hash('sha256', $orderId.'|'.$payment->ppa_id.'|'.$input['operation_uuid']);
            $existing = DB::table('stj_powertranz_operaciones')->where('pto_uuid', $input['operation_uuid'])->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->pto_pago_id !== (int) $payment->ppa_id || ! hash_equals($existing->pto_payload_hash, $fingerprint)) {
                    throw new CartOperationConflict('operation_uuid ya fue utilizado con otro contenido.');
                }

                $replay = json_decode(Crypt::decryptString($existing->pto_respuesta_segura), true);
                if ($existing->pto_estado === 'PENDIENTE') {
                    throw ValidationException::withMessages(['payment' => 'Este intento ya inicio 3DS. Consulta su estado antes de crear otro intento.']);
                }

                return $replay;
            }
            $returnToken = Str::random(64);
            $returnUrl = ($this->urls ?? app(PowerTranzUrlFactory::class))->returnUrl($country, $returnToken);
            $payload = $this->payloads->sale($order, $payment, $input['card'], $configuration['currency'], $input['operation_uuid'], $returnUrl);
            $this->assertAuthorizedAmount($orderId, $payment);
            DB::table('stj_pedidos_pago')->where('ppa_id', $payment->ppa_id)->update([
                'ppa_emisor' => ($this->cardBrands ?? app(CardBrandDetector::class))->detect((string) $input['card']['pan']),
            ]);
            $gateway = $this->client->sale($configuration, $payload, $input['operation_uuid']);
            $this->verifyInitialResponse($gateway, $payment, $configuration['currency'], $input['operation_uuid']);
            $result = $this->safeInitialResult($gateway, $payment, $country, $input['operation_uuid']);
            $storedResult = $result;
            unset($storedResult['redirectData']);
            DB::table('stj_powertranz_operaciones')->insert(['pto_uuid' => $input['operation_uuid'], 'pto_pago_id' => $payment->ppa_id, 'pto_return_token_hash' => hash('sha256', $returnToken), 'pto_payload_hash' => $fingerprint, 'pto_estado' => $result['status'], 'pto_respuesta_segura' => Crypt::encryptString(json_encode($storedResult, JSON_UNESCAPED_SLASHES)), 'pto_creado_en' => now(), 'pto_actualizado_en' => now()]);
            DB::table('stj_pedidos_pago')->where('ppa_id', $payment->ppa_id)->update(['ppa_transactionidentifier' => $input['operation_uuid'], 'ppa_estado' => $result['status'] === 'DENEGADA' ? 'DENEGADA' : 'PENDIENTE', 'ppa_fecha_procesado' => now()]);
            if (in_array($result['status'], ['APROBADA', 'DENEGADA'], true)) {
                $this->events->record((int) $payment->ppa_id, $result['status'], (string) Str::uuid(), ['provider' => 'POWERTRANZ']);
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
            if (! in_array($operation->pto_estado, ['APROBADA', 'DENEGADA'], true) && now()->greaterThan(
                Carbon::parse($operation->pto_creado_en)->addMinutes(max(1, (int) config('powertranz.return_token_ttl_minutes', 60)))
            )) {
                throw ValidationException::withMessages(['return' => 'El retorno PowerTranz expiro.']);
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
            $this->verifyConfirmation($gateway, $payment, $configuration['currency'], (string) $operation->pto_uuid);
            $status = ($gateway['Approved'] ?? false) === true ? 'APROBADA' : 'DENEGADA';
            $result = ['status' => $status, 'orderId' => (int) $order->ped_id, 'paymentId' => (int) $payment->ppa_id, 'reference' => (string) $payment->ppa_ref];
            DB::table('stj_powertranz_operaciones')->where('pto_id', $operation->pto_id)->update(['pto_estado' => $status, 'pto_respuesta_segura' => Crypt::encryptString(json_encode($result)), 'pto_actualizado_en' => now()]);
            DB::table('stj_pedidos_pago')->where('ppa_id', $payment->ppa_id)->update([
                'ppa_autorizacion' => $this->safeGatewayText($gateway['AuthorizationCode'] ?? null, 100),
                'ppa_rsp_codigo' => $this->safeGatewayText($gateway['IsoResponseCode'] ?? null, 30),
                'ppa_rsp_mensaje' => $this->safeGatewayText($gateway['ResponseMessage'] ?? null, 255),
                'ppa_transactionidentifier' => (string) $operation->pto_uuid,
                'ppa_fecha_procesado' => now(),
            ]);
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

    private function verifyConfirmation(array $response, object $payment, string $currency, ?string $transaction = null): void
    {
        if ($transaction !== null && (string) ($response['TransactionIdentifier'] ?? '') !== $transaction) {
            throw ValidationException::withMessages(['return' => 'La operacion confirmada no coincide.']);
        }
        if (($transaction !== null || array_key_exists('OrderIdentifier', $response)) && (string) ($response['OrderIdentifier'] ?? '') !== (string) $payment->ppa_ref) {
            throw ValidationException::withMessages(['return' => 'La referencia confirmada no coincide.']);
        }
        if (($transaction !== null || array_key_exists('CurrencyCode', $response)) && (string) ($response['CurrencyCode'] ?? '') !== $currency) {
            throw ValidationException::withMessages(['return' => 'La moneda confirmada no coincide.']);
        }
        if (($transaction !== null || array_key_exists('TotalAmount', $response)) && (! array_key_exists('TotalAmount', $response) || $this->cents((string) $response['TotalAmount']) !== $this->cents((string) $payment->ppa_monto))) {
            throw ValidationException::withMessages(['return' => 'El importe confirmado no coincide.']);
        }
    }

    private function verifyInitialResponse(array $response, object $payment, string $currency, string $transaction): void
    {
        if (isset($response['TransactionIdentifier']) && (string) $response['TransactionIdentifier'] !== $transaction) {
            throw ValidationException::withMessages(['powertranz' => 'PowerTranz devolvio un identificador no coincidente.']);
        }
        $this->verifyConfirmation($response, $payment, $currency);
        if (filled($response['RedirectData'] ?? null) && ! preg_match('/<form\b|<script\b|<iframe\b/i', (string) $response['RedirectData'])) {
            throw ValidationException::withMessages(['powertranz' => 'PowerTranz devolvio RedirectData invalido.']);
        }
    }

    private function assertAuthorizedAmount(int $orderId, object $payment): void
    {
        $subtotal = DB::table('stj_pedidos_detalle')->where('car_ref', $payment->ppa_ref)
            ->selectRaw('COALESCE(SUM(ROUND(car_precio * car_cantidad * (100 - COALESCE(car_descuento_final, car_descuento, 0)) / 100, 2)), 0) total')
            ->value('total');
        $order = DB::table('stj_pedidos')->where('ped_id', $orderId)->first(['ped_checkout']);
        $shipping = $order?->ped_checkout === 'DOMICILIO'
            ? DB::table('stj_pedidos_direccion')->where('pdi_pedido', $orderId)->value('pdi_costo_envio_final')
            : '0';
        $calculated = $this->cents((string) $subtotal) + $this->cents((string) $shipping);
        if ($calculated <= 0 || $calculated !== $this->cents((string) $payment->ppa_monto) || $this->cents((string) $subtotal) !== $this->cents((string) $payment->ppa_monto_senv)) {
            throw ValidationException::withMessages(['payment' => 'El importe persistido no coincide con el pedido recalculado.']);
        }
    }

    private function cents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', trim($value)), 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function safeGatewayText(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
