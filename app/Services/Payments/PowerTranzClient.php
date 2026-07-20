<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PowerTranzClient
{
    public function sale(array $configuration, array $payload, string $correlationId): array
    {
        return $this->post($configuration['sale_url'], $configuration, $payload, $correlationId);
    }

    public function confirm(array $configuration, string $spiToken, string $correlationId): array
    {
        try {
            $response = Http::connectTimeout($configuration['connect_timeout'])->timeout($configuration['timeout'])->acceptJson()->withHeaders(['PowerTranz-PowerTranzId' => $configuration['id'], 'PowerTranz-PowerTranzPassword' => $configuration['password'], 'X-Correlation-ID' => $correlationId])->withBody(json_encode($spiToken), 'application/json')->post($configuration['payment_url']);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['powertranz' => 'No fue posible conectar con PowerTranz.']);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw ValidationException::withMessages(['powertranz' => 'PowerTranz no pudo confirmar el resultado 3DS.']);
        }

        return $response->json();
    }

    private function post(string $url, array $configuration, array $payload, string $correlationId): array
    {
        try {
            $response = Http::connectTimeout($configuration['connect_timeout'])->timeout($configuration['timeout'])->acceptJson()->withHeaders(['PowerTranz-PowerTranzId' => $configuration['id'], 'PowerTranz-PowerTranzPassword' => $configuration['password'], 'X-Correlation-ID' => $correlationId])->post($url, $payload);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['powertranz' => 'No fue posible conectar con PowerTranz.']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['powertranz' => "PowerTranz respondio HTTP {$response->status()}."]);
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw ValidationException::withMessages(['powertranz' => 'PowerTranz devolvio una respuesta invalida.']);
        }

        return $data;
    }
}
