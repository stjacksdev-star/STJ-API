<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\Http;

class InventorySyncClient
{
    /**
     * @param  array<int, string>  $productCodes
     * @return array{ok: bool, rows: array<int, array<string, mixed>>, error?: string}
     */
    public function fetch(
        int $countryId,
        string $endpointProfile,
        string $storeCode,
        array $productCodes,
    ): array {
        $url = trim((string) config("inventory.sync.endpoints.{$endpointProfile}", ''));
        $token = trim((string) config('inventory.sync.token', ''));

        if ($url === '' || $token === '') {
            return [
                'ok' => false,
                'rows' => [],
                'error' => "Configuracion incompleta para el perfil {$endpointProfile}.",
            ];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(max(1, (int) config('inventory.sync.connect_timeout_seconds', 5)))
                ->timeout(max(1, (int) config('inventory.sync.timeout_seconds', 20)))
                ->retry(
                    max(0, (int) config('inventory.sync.retry_times', 2)),
                    max(0, (int) config('inventory.sync.retry_delay_ms', 500)),
                    throw: false,
                )
                ->post($url, [
                    'Pais' => (string) $countryId,
                    'Codigos' => collect($productCodes)
                        ->map(fn (string $code) => "'{$code}'")
                        ->implode(','),
                    'Tienda' => $storeCode,
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'error' => "HTTP {$response->status()} al consultar inventario.",
                ];
            }

            $payload = $response->json();
            if (! is_array($payload) || empty($payload['ok']) || ! isset($payload['registros']['existencia']) || ! is_array($payload['registros']['existencia'])) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'error' => 'La API de inventario devolvio una estructura invalida.',
                ];
            }

            $requested = array_fill_keys($productCodes, true);
            $rows = collect($payload['registros']['existencia'])
                ->map(function (mixed $row) use ($storeCode): ?array {
                    if (! is_array($row)) {
                        return null;
                    }

                    $code = trim((string) ($row['estilo'] ?? $row['codigo'] ?? ''));
                    $size = trim((string) ($row['talla'] ?? ''));

                    if ($code === '' || $size === '') {
                        return null;
                    }

                    return [
                        'code' => $code,
                        'size' => $size,
                        'quantity' => max(0, (int) ($row['existencia'] ?? $row['cantidad'] ?? 0)),
                        'store' => $storeCode,
                    ];
                })
                ->filter(fn (?array $row) => $row !== null && isset($requested[$row['code']]))
                ->unique(fn (array $row) => $row['code'].'|'.$row['size'])
                ->values()
                ->all();

            return ['ok' => true, 'rows' => $rows];
        } catch (\Throwable $exception) {
            report($exception);

            return ['ok' => false, 'rows' => [], 'error' => $exception->getMessage()];
        }
    }
}
