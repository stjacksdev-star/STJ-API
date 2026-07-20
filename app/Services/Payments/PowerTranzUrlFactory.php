<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;

class PowerTranzUrlFactory
{
    public function returnUrl(string $country, string $token): string
    {
        $base = rtrim((string) config('powertranz.return_base_url'), '/');
        $this->assertUrl($base, 'La URL de retorno PowerTranz no esta configurada correctamente.');

        return $base.'/'.rawurlencode(strtolower($country)).'/'.rawurlencode($token);
    }

    public function frontendResultUrl(string $country, string $hint): string
    {
        $template = trim((string) config('powertranz.frontend_result_url'));
        if (! str_contains($template, '{country}') || ! str_contains($template, '{hint}')) {
            throw ValidationException::withMessages(['powertranz' => 'La URL final de PowerTranz debe incluir {country} y {hint}.']);
        }
        $url = str_replace(['{country}', '{hint}'], [rawurlencode(strtolower($country)), rawurlencode(strtolower($hint))], $template);
        $this->assertUrl($url, 'La URL final de PowerTranz no esta configurada correctamente.');

        return $url;
    }

    private function assertUrl(string $url, string $message): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['powertranz' => $message]);
        }
    }
}
