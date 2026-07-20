<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class PowerTranzPayloadFactory
{
    public function sale(object $order, object $payment, array $card, string $currency, string $transactionIdentifier, string $returnUrl): array
    {
        $total = $this->decimal((string) $payment->ppa_monto);
        if ($this->cents((string) $total) <= 0) {
            throw ValidationException::withMessages(['payment' => 'El importe autorizado debe ser mayor que cero.']);
        }
        $payload = [
            'TransactionIdentifier' => $transactionIdentifier,
            'TotalAmount' => $total,
            'CurrencyCode' => $currency,
            'ThreeDSecure' => true,
            'Source' => ['CardPresent' => false, 'CardEmvFallback' => false, 'ManualEntry' => false, 'Debit' => false, 'Contactless' => false, 'CardPan' => preg_replace('/\s+/', '', (string) $card['pan']), 'CardCvv' => (string) $card['cvv'], 'CardExpiration' => (string) $card['expiration'], 'CardholderName' => trim(Str::ascii((string) $card['holder']))],
            'OrderIdentifier' => (string) $payment->ppa_ref,
            'BillingAddress' => ['FirstName' => trim(Str::ascii((string) $order->ped_nombres)), 'LastName' => trim(Str::ascii((string) $order->ped_apellidos)), 'Line1' => '', 'Line2' => '', 'City' => '', 'State' => '', 'PostalCode' => '', 'CountryCode' => '', 'EmailAddress' => trim((string) $order->ped_email), 'PhoneNumber' => preg_replace('/\D+/', '', (string) ($order->ped_telefono_pais ?? '').(string) ($order->ped_telefono ?? ''))],
            'AddressMatch' => false,
            'ExtendedData' => ['ThreeDSecure' => ['ChallengeWindowSize' => 4], 'MerchantResponseUrl' => $returnUrl],
        ];
        if (strtoupper((string) $order->ped_pais) === 'HN') {
            $totalCents = $this->cents((string) $total);
            $taxCents = (int) round($totalCents * 15 / 115, 0, PHP_ROUND_HALF_UP);
            $payload['TaxAmount'] = $taxCents / 100;
        }

        return $payload;
    }

    private function decimal(string $value): float
    {
        return $this->cents($value) / 100;
    }

    private function cents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', trim($value)), 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

}
