<?php

namespace App\Services;

use App\Services\Inventory\ExternalInventoryProvider;
use App\Services\Inventory\InventorySourceResolver;
use App\Services\Inventory\LocalInventoryProvider;
use Illuminate\Support\Facades\DB;

class ProductListAvailabilityService
{
    public function __construct(
        private readonly InventorySourceResolver $resolver,
        private readonly ExternalInventoryProvider $externalProvider,
        private readonly LocalInventoryProvider $localProvider,
    ) {
    }

    public function summarize(string $countryCode, array $products, ?string $storeCode = null): array
    {
        $country = $this->resolveCountry($countryCode);

        if (! $country || $products === []) {
            return [
                'availabilityBySku' => [],
                'activeStoreCode' => null,
                'usedSource' => null,
            ];
        }

        $countryCode = strtolower((string) $country->pai_codigo);
        $activeStoreCode = trim((string) ($storeCode ?: config("inventory.default_store_by_country.{$countryCode}", '')));
        $productCodes = collect($products)
            ->pluck('pro_codigo')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($activeStoreCode === '' || $productCodes === []) {
            return [
                'availabilityBySku' => [],
                'activeStoreCode' => $activeStoreCode ?: null,
                'usedSource' => null,
            ];
        }

        $rule = $this->resolver->resolve($countryCode, 'product_list');
        $result = $this->fetchAvailability(
            (int) $country->pai_id,
            $countryCode,
            $activeStoreCode,
            $productCodes,
            (string) $rule['source'],
            $rule['fallback_source'] ?? null,
        );

        $availabilityBySku = collect($result['rows'])
            ->filter(fn (array $row) => (int) ($row['existencia'] ?? 0) > 0)
            ->groupBy('estilo')
            ->map(function ($rows) {
                $sizes = collect($rows)
                    ->pluck('talla')
                    ->map(fn ($size) => trim((string) $size))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'availableSizes' => $sizes,
                    'hasStock' => count($sizes) > 0,
                    'totalQuantity' => (int) collect($rows)->sum(fn (array $row) => max(0, (int) $row['existencia'])),
                ];
            })
            ->all();

        return [
            'availabilityBySku' => $availabilityBySku,
            'activeStoreCode' => $activeStoreCode,
            'usedSource' => $result['used_source'] ?? null,
        ];
    }

    private function fetchAvailability(
        int $countryId,
        string $countryCode,
        string $storeCode,
        array $productCodes,
        string $source,
        ?string $fallbackSource,
    ): array {
        $result = $this->callProvider($source, $countryId, $countryCode, $storeCode, $productCodes);

        if ($result['ok'] || ! $fallbackSource) {
            return [
                ...$result,
                'used_source' => $source,
            ];
        }

        $fallbackResult = $this->callProvider($fallbackSource, $countryId, $countryCode, $storeCode, $productCodes);

        return [
            ...$fallbackResult,
            'used_source' => $fallbackResult['ok'] ? $fallbackSource : $source,
        ];
    }

    private function callProvider(string $source, int $countryId, string $countryCode, string $storeCode, array $productCodes): array
    {
        return match ($source) {
            'external_api' => $this->externalProvider->fetchProductListAvailability($countryId, $countryCode, $storeCode, $productCodes),
            'local_inventory' => $this->localProvider->fetchProductListAvailability($countryId, $storeCode, $productCodes),
            default => [
                'ok' => false,
                'rows' => [],
                'source' => $source,
                'error' => "Fuente de inventario no soportada: {$source}",
            ],
        };
    }

    private function resolveCountry(string $countryCode): ?object
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo'])
            ->where('pai_codigo', strtoupper($countryCode))
            ->first();
    }
}
