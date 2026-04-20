<?php

namespace App\Services;

use App\Services\Inventory\ExternalInventoryProvider;
use App\Services\Inventory\InventorySourceResolver;
use App\Services\Inventory\LocalInventoryProvider;
use Illuminate\Support\Facades\DB;

class StorefrontCheckoutValidationService
{
    private $resolver;
    private $externalProvider;
    private $localProvider;

    public function __construct(
        InventorySourceResolver $resolver,
        ExternalInventoryProvider $externalProvider,
        LocalInventoryProvider $localProvider
    ) {
        $this->resolver = $resolver;
        $this->externalProvider = $externalProvider;
        $this->localProvider = $localProvider;
    }

    public function validate(string $countryCode, array $fulfillment, array $items): array
    {
        $country = $this->resolveCountry($countryCode);

        if (! $country) {
            return [
                'ok' => false,
                'message' => 'Pais no soportado para validacion de checkout.',
                'lines' => [],
            ];
        }

        $countryCode = strtolower((string) $country->pai_codigo);
        $storeCode = $this->resolveStoreCode($countryCode, $fulfillment);

        if ($storeCode === '') {
            return [
                'ok' => false,
                'message' => 'No se pudo resolver la tienda para validar inventario.',
                'lines' => $this->emptyLines($items, 'Tienda pendiente de definir.'),
            ];
        }

        $rule = $this->resolver->resolve($countryCode, 'checkout');
        $productCodes = collect($items)
            ->pluck('sku')
            ->map(function ($sku) {
                return trim((string) $sku);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $providerResult = $this->fetchAvailability(
            (int) $country->pai_id,
            $countryCode,
            $storeCode,
            $productCodes,
            (string) $rule['source'],
            $rule['fallback_source'] ?? null,
        );

        if (! $providerResult['ok']) {
            return [
                'ok' => false,
                'message' => $providerResult['error'] ?? 'No se pudo validar inventario de checkout.',
                'inventorySource' => [
                    'configuredSource' => $rule['source'],
                    'usedSource' => $providerResult['used_source'] ?? $rule['source'],
                    'fallbackSource' => $rule['fallback_source'] ?? null,
                    'fallbackTriggered' => (bool) ($providerResult['fallback_triggered'] ?? false),
                ],
                'store' => $this->storePayload($countryCode, $storeCode),
                'lines' => $this->emptyLines($items, 'Inventario no disponible para validacion.'),
            ];
        }

        $rows = collect($providerResult['rows']);
        $lines = collect($items)
            ->map(function (array $item) use ($rows, $storeCode) {
                $sku = trim((string) ($item['sku'] ?? ''));
                $size = trim((string) ($item['size'] ?? ''));
                $requestedQuantity = max(1, (int) ($item['quantity'] ?? 1));
                $availableQuantity = (int) $rows
                    ->filter(function (array $row) use ($sku) {
                        return trim((string) $row['estilo']) === $sku;
                    })
                    ->filter(function (array $row) use ($size) {
                        return trim((string) $row['talla']) === $size;
                    })
                    ->filter(function (array $row) use ($storeCode) {
                        return trim((string) $row['codTienda']) === $storeCode;
                    })
                    ->sum(function (array $row) {
                        return max(0, (int) $row['existencia']);
                    });

                return [
                    'key' => $item['key'] ?? "{$sku}:{$size}",
                    'sku' => $sku,
                    'name' => $item['name'] ?? $sku,
                    'size' => $size,
                    'requestedQuantity' => $requestedQuantity,
                    'availableQuantity' => $availableQuantity,
                    'ok' => $availableQuantity >= $requestedQuantity,
                    'message' => $availableQuantity >= $requestedQuantity
                        ? 'Stock suficiente.'
                        : "Solo hay {$availableQuantity} unidad(es) disponibles.",
                ];
            })
            ->values()
            ->all();

        $allLinesOk = collect($lines)->every(function (array $line) {
            return (bool) $line['ok'];
        });

        return [
            'ok' => $allLinesOk,
            'message' => $allLinesOk
                ? 'Checkout validado correctamente.'
                : 'Hay productos sin stock suficiente para el metodo de entrega elegido.',
            'inventorySource' => [
                'configuredSource' => $rule['source'],
                'usedSource' => $providerResult['used_source'],
                'fallbackSource' => $rule['fallback_source'] ?? null,
                'fallbackTriggered' => (bool) $providerResult['fallback_triggered'],
            ],
            'store' => $this->storePayload($countryCode, $storeCode),
            'lines' => $lines,
        ];
    }

    private function fetchAvailability(
        int $countryId,
        string $countryCode,
        string $storeCode,
        array $productCodes,
        string $source,
        ?string $fallbackSource
    ): array {
        $result = $this->callProvider($source, $countryId, $countryCode, $storeCode, $productCodes);

        if ($result['ok'] || ! $fallbackSource) {
            return array_merge($result, [
                'used_source' => $source,
                'fallback_triggered' => false,
            ]);
        }

        $fallbackResult = $this->callProvider($fallbackSource, $countryId, $countryCode, $storeCode, $productCodes);

        return array_merge($fallbackResult, [
            'used_source' => $fallbackResult['ok'] ? $fallbackSource : $source,
            'fallback_triggered' => $fallbackResult['ok'],
        ]);
    }

    private function callProvider(string $source, int $countryId, string $countryCode, string $storeCode, array $productCodes): array
    {
        switch ($source) {
            case 'external_api':
                return $this->externalProvider->fetchProductListAvailability($countryId, $countryCode, $storeCode, $productCodes);

            case 'local_inventory':
                return $this->localProvider->fetchProductListAvailability($countryId, $storeCode, $productCodes);

            default:
                return [
                'ok' => false,
                'rows' => [],
                'source' => $source,
                'error' => "Fuente de inventario no soportada: {$source}",
                ];
        }
    }

    private function resolveCountry(string $countryCode): ?object
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo'])
            ->where('pai_codigo', strtoupper($countryCode))
            ->first();
    }

    private function resolveStoreCode(string $countryCode, array $fulfillment): string
    {
        $method = trim((string) ($fulfillment['method'] ?? 'home_delivery'));
        $storeCode = trim((string) ($fulfillment['storeCode'] ?? ''));

        if ($method === 'store_pickup') {
            return $storeCode;
        }

        return trim((string) config("inventory.domicilio_store_by_country.{$countryCode}", config("inventory.default_store_by_country.{$countryCode}", '')));
    }

    private function storePayload(string $countryCode, string $storeCode): array
    {
        $storeName = DB::table('stj_tiendas')
            ->where('tie_codigo', $storeCode)
            ->value('tie_nombre');

        $domicilioCode = config("inventory.domicilio_store_by_country.{$countryCode}");

        return [
            'code' => $storeCode,
            'name' => $domicilioCode && trim((string) $domicilioCode) === $storeCode
                ? 'Domicilio'
                : trim((string) ($storeName ?: $storeCode)),
        ];
    }

    private function emptyLines(array $items, string $message): array
    {
        return collect($items)
            ->map(function (array $item) use ($message) {
                return [
                'key' => $item['key'] ?? trim((string) ($item['sku'] ?? '')),
                'sku' => trim((string) ($item['sku'] ?? '')),
                'name' => $item['name'] ?? trim((string) ($item['sku'] ?? '')),
                'size' => trim((string) ($item['size'] ?? '')),
                'requestedQuantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'availableQuantity' => 0,
                'ok' => false,
                'message' => $message,
                ];
            })
            ->values()
            ->all();
    }
}
