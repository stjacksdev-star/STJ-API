<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;

class PowerTranzConfigResolver
{
    public function forCountry(string $countryCode, string $origin = 'WEB'): array
    {
        $country = strtolower(trim($countryCode));
        $origin = strtoupper(trim($origin));
        if (! in_array($origin, ['WEB', 'APP'], true)) {
            throw ValidationException::withMessages(['powertranz' => 'El origen del pedido no es valido para PowerTranz.']);
        }
        $environment = strtolower((string) config('powertranz.environment'));
        if (! in_array($environment, ['staging', 'production'], true)) {
            throw ValidationException::withMessages(['powertranz' => 'El ambiente PowerTranz no es valido.']);
        }
        $saleUrl = trim((string) config('powertranz.sale_url'));
        $paymentUrl = trim((string) config('powertranz.payment_url'));
        $refundUrl = trim((string) config('powertranz.refund_url'));
        $credentialGroup = $country === 'sv' && $origin === 'APP' ? 'app_credentials' : 'credentials';
        $credentials = config("powertranz.{$credentialGroup}.{$country}", []);
        $currency = (string) config("powertranz.currencies.{$country}", '');
        foreach ([$saleUrl, $paymentUrl, $refundUrl] as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw ValidationException::withMessages(['powertranz' => 'La URL HTTPS de PowerTranz no esta configurada correctamente.']);
            }
        }
        $expectedHost = $environment === 'staging' ? 'staging.ptranz.com' : 'gateway.ptranz.com';
        if (parse_url($saleUrl, PHP_URL_HOST) !== $expectedHost || parse_url($paymentUrl, PHP_URL_HOST) !== $expectedHost
            || parse_url($refundUrl, PHP_URL_HOST) !== $expectedHost) {
            throw ValidationException::withMessages(['powertranz' => 'Las URLs no corresponden al ambiente PowerTranz seleccionado.']);
        }
        if (blank($credentials['id'] ?? null) || blank($credentials['password'] ?? null) || ! preg_match('/^\d{3}$/', $currency)) {
            throw ValidationException::withMessages(['powertranz' => "PowerTranz no esta configurado para {$country} ({$origin})."]);
        }

        return ['environment' => $environment, 'sale_url' => $saleUrl, 'payment_url' => $paymentUrl, 'refund_url' => $refundUrl, 'host' => $expectedHost, 'id' => (string) $credentials['id'], 'password' => (string) $credentials['password'], 'currency' => $currency, 'connect_timeout' => max(1, (int) config('powertranz.connect_timeout', 5)), 'timeout' => max(1, (int) config('powertranz.timeout', 20))];
    }
}
