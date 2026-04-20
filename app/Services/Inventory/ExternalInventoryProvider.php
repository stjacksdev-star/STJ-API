<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\Http;

class ExternalInventoryProvider
{
    public function fetchProductListAvailability(int $countryId, string $countryCode, string $storeCode, array $productCodes): array
    {
        $url = $this->resolveProductListUrl($countryCode);
        $token = trim((string) config('inventory.external.token', ''));

        if ($url === '' || $token === '' || $productCodes === []) {
            return [
                'ok' => false,
                'rows' => [],
                'source' => 'external_api',
                'error' => 'La configuracion de inventario externo para catalogo esta incompleta.',
            ];
        }

        $payload = [
            'Pais' => (string) $countryId,
            'Codigos' => collect($productCodes)->map(fn ($code) => "'".trim((string) $code)."'")->implode(','),
            'Tienda' => trim($storeCode),
        ];

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('inventory.external.timeout_seconds', 8))
                ->acceptJson()
                ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')
                ->post($url);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'source' => 'external_api',
                    'error' => "HTTP {$response->status()} al consultar inventario externo de catalogo.",
                ];
            }

            $data = $response->json();

            return [
                'ok' => true,
                'rows' => $this->normalizeRows($data),
                'source' => 'external_api',
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'rows' => [],
                'source' => 'external_api',
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function fetchProductDetailAvailability(int $countryId, string $countryCode, array $storeCodes, string $productCode): array
    {
        $url = $this->resolveUrl($countryCode);
        $token = trim((string) config('inventory.external.token', ''));

        if ($url === '' || $token === '') {
            return [
                'ok' => false,
                'rows' => [],
                'source' => 'external_api',
                'error' => 'La configuracion de inventario externo esta incompleta.',
            ];
        }

        $payload = [
            'Pais' => (string) $countryId,
            'Codigos' => "'{$productCode}'",
            'Tiendas' => collect($storeCodes)
                ->map(fn ($storeCode) => "'".trim((string) $storeCode)."'")
                ->implode(','),
        ];

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('inventory.external.timeout_seconds', 8))
                ->acceptJson()
                ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')
                ->post($url);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'source' => 'external_api',
                    'error' => "HTTP {$response->status()} al consultar inventario externo.",
                ];
            }

            $data = $response->json();

            return [
                'ok' => true,
                'rows' => $this->normalizeRows($data),
                'source' => 'external_api',
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'rows' => [],
                'source' => 'external_api',
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function resolveUrl(string $countryCode): string
    {
        return strtolower($countryCode) === 'sv'
            ? trim((string) config('inventory.external.sv_detail_url', ''))
            : trim((string) config('inventory.external.generic_detail_url', ''));
    }

    private function resolveProductListUrl(string $countryCode): string
    {
        return strtolower($countryCode) === 'sv'
            ? trim((string) config('inventory.external.sv_categories_url', ''))
            : trim((string) config('inventory.external.generic_categories_url', ''));
    }

    private function normalizeRows(mixed $data): array
    {
        $rows = [];

        if (isset($data['RESULTADO']) && ! empty($data['RESULTADO']) && isset($data['datos']) && is_array($data['datos'])) {
            $rows = $data['datos'];
        } elseif (isset($data['ok']) && ! empty($data['ok']) && isset($data['registros']['existencia']) && is_array($data['registros']['existencia'])) {
            $rows = $data['registros']['existencia'];
        }

        return collect($rows)
            ->map(function ($row) {
                $style = $row['estilo'] ?? $row['codigo'] ?? $row['inv_codigo'] ?? null;
                $size = $row['talla'] ?? $row['inv_talla'] ?? null;
                $quantity = $row['existencia'] ?? $row['cantidad'] ?? $row['inv_cantidad'] ?? 0;
                $storeCode = $row['codTienda'] ?? $row['tiendaCodigo'] ?? $row['inv_tienda'] ?? null;
                $storeName = $row['tienda'] ?? $row['tiendaNombre'] ?? null;

                return [
                    'estilo' => trim((string) $style),
                    'talla' => trim((string) $size),
                    'existencia' => (int) $quantity,
                    'codTienda' => trim((string) $storeCode),
                    'tienda' => trim((string) $storeName),
                ];
            })
            ->filter(fn (array $row) => $row['estilo'] !== '' && $row['talla'] !== '' && $row['codTienda'] !== '')
            ->values()
            ->all();
    }
}
