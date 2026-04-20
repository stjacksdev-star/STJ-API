<?php

namespace App\Services;

use App\Services\Inventory\ExternalInventoryProvider;
use App\Services\Inventory\InventorySourceResolver;
use App\Services\Inventory\LocalInventoryProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductDetailAvailabilityService
{
    public function __construct(
        private readonly InventorySourceResolver $resolver,
        private readonly ExternalInventoryProvider $externalProvider,
        private readonly LocalInventoryProvider $localProvider,
    ) {
    }

    public function forCountryAndSlug(string $countryCode, string $slug, ?string $storeCode = null): ?array
    {
        $country = $this->resolveCountry($countryCode);
        $productId = $this->extractProductId($slug);

        if (! $country || ! $productId) {
            return null;
        }

        $product = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->where('pp.ppa_pais', $country->pai_id)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO')
            ->where('p.pro_id', $productId)
            ->select([
                'p.pro_id',
                'p.pro_codigo',
                'p.pro_nombre',
                'p.pro_tallas',
            ])
            ->first();

        if (! $product) {
            return null;
        }

        $countryCode = strtolower((string) $country->pai_codigo);
        $storeCodes = config("inventory.stores_by_country.{$countryCode}", []);
        $activeStoreCode = trim((string) ($storeCode ?: config("inventory.default_store_by_country.{$countryCode}", '')));

        if ($storeCodes === [] || $activeStoreCode === '') {
            return [
                'product' => $this->normalizeProduct($product),
                'inventorySource' => [
                    'configuredSource' => null,
                    'usedSource' => null,
                    'fallbackSource' => null,
                    'fallbackTriggered' => false,
                ],
                'activeStore' => null,
                'sizes' => $this->buildEmptySizes((string) $product->pro_tallas),
                'message' => 'No hay configuracion de tiendas para este pais.',
            ];
        }

        $rule = $this->resolver->resolve($countryCode, 'product_detail');
        $providerResult = $this->fetchAvailability(
            (int) $country->pai_id,
            $countryCode,
            $storeCodes,
            (string) $product->pro_codigo,
            (string) $rule['source'],
            $rule['fallback_source'] ?? null,
        );

        $storeNames = $this->storeNamesByCode($storeCodes);
        $activeStore = [
            'code' => $activeStoreCode,
            'name' => $this->normalizeStoreName($countryCode, $activeStoreCode, $storeNames[$activeStoreCode] ?? null),
        ];

        return [
            'product' => $this->normalizeProduct($product),
            'inventorySource' => [
                'configuredSource' => $rule['source'],
                'usedSource' => $providerResult['used_source'],
                'fallbackSource' => $rule['fallback_source'] ?? null,
                'fallbackTriggered' => (bool) $providerResult['fallback_triggered'],
            ],
            'activeStore' => $activeStore,
            'sizes' => $this->buildSizes(
                (string) $product->pro_tallas,
                $providerResult['rows'],
                $activeStoreCode,
                $countryCode,
                $storeNames,
            ),
            'message' => $providerResult['ok']
                ? null
                : ($providerResult['error'] ?? 'No se pudo consultar la disponibilidad del producto.'),
        ];
    }

    private function fetchAvailability(
        int $countryId,
        string $countryCode,
        array $storeCodes,
        string $productCode,
        string $source,
        ?string $fallbackSource,
    ): array {
        $result = $this->callProvider($source, $countryId, $countryCode, $storeCodes, $productCode);

        if ($result['ok'] || ! $fallbackSource) {
            return [
                ...$result,
                'used_source' => $source,
                'fallback_triggered' => false,
            ];
        }

        $fallbackResult = $this->callProvider($fallbackSource, $countryId, $countryCode, $storeCodes, $productCode);

        return [
            ...$fallbackResult,
            'used_source' => $fallbackResult['ok'] ? $fallbackSource : $source,
            'fallback_triggered' => $fallbackResult['ok'],
        ];
    }

    private function callProvider(
        string $source,
        int $countryId,
        string $countryCode,
        array $storeCodes,
        string $productCode,
    ): array {
        return match ($source) {
            'external_api' => $this->externalProvider->fetchProductDetailAvailability($countryId, $countryCode, $storeCodes, $productCode),
            'local_inventory' => $this->localProvider->fetchProductDetailAvailability($countryId, $storeCodes, $productCode),
            default => [
                'ok' => false,
                'rows' => [],
                'source' => $source,
                'error' => "Fuente de inventario no soportada: {$source}",
            ],
        };
    }

    private function buildSizes(string $declaredSizes, array $rows, string $activeStoreCode, string $countryCode, array $storeNames): array
    {
        $orderedSizes = collect(explode(',', $declaredSizes))
            ->map(fn ($size) => trim($size))
            ->filter()
            ->values();

        $inventorySizes = collect($rows)
            ->pluck('talla')
            ->map(fn ($size) => trim((string) $size))
            ->filter();

        $sizes = $orderedSizes
            ->merge($inventorySizes)
            ->unique()
            ->values();

        return $sizes
            ->map(function (string $size) use ($rows, $activeStoreCode, $countryCode, $storeNames) {
                $sizeRows = collect($rows)->filter(fn (array $row) => trim((string) $row['talla']) === $size);
                $activeRow = $sizeRows->first(fn (array $row) => trim((string) $row['codTienda']) === $activeStoreCode);
                $activeQuantity = max(0, (int) ($activeRow['existencia'] ?? 0));

                $alternativeStores = $sizeRows
                    ->filter(fn (array $row) => trim((string) $row['codTienda']) !== $activeStoreCode && (int) $row['existencia'] > 0)
                    ->map(fn (array $row) => [
                        'code' => trim((string) $row['codTienda']),
                        'name' => $this->normalizeStoreName($countryCode, (string) $row['codTienda'], $row['tienda'] ?: ($storeNames[$row['codTienda']] ?? null)),
                        'quantity' => (int) $row['existencia'],
                    ])
                    ->values()
                    ->all();

                return [
                    'size' => $size,
                    'quantityInActiveStore' => $activeQuantity,
                    'availableInActiveStore' => $activeQuantity > 0,
                    'availableElsewhere' => count($alternativeStores) > 0,
                    'alternativeStores' => $alternativeStores,
                    'totalQuantity' => (int) $sizeRows->sum(fn (array $row) => max(0, (int) $row['existencia'])),
                ];
            })
            ->values()
            ->all();
    }

    private function buildEmptySizes(string $declaredSizes): array
    {
        return collect(explode(',', $declaredSizes))
            ->map(fn ($size) => trim($size))
            ->filter()
            ->values()
            ->map(fn (string $size) => [
                'size' => $size,
                'quantityInActiveStore' => 0,
                'availableInActiveStore' => false,
                'availableElsewhere' => false,
                'alternativeStores' => [],
                'totalQuantity' => 0,
            ])
            ->all();
    }

    private function normalizeProduct(object $product): array
    {
        return [
            'id' => (int) $product->pro_id,
            'sku' => trim((string) $product->pro_codigo),
            'name' => trim((string) $product->pro_nombre),
            'slug' => Str::slug((string) $product->pro_nombre).'-'.$product->pro_id,
        ];
    }

    private function resolveCountry(string $countryCode): ?object
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo'])
            ->where('pai_codigo', strtoupper($countryCode))
            ->first();
    }

    private function extractProductId(string $slug): ?int
    {
        if (! preg_match('/-(\d+)$/', $slug, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function storeNamesByCode(array $storeCodes): array
    {
        return DB::table('stj_tiendas')
            ->whereIn('tie_codigo', $storeCodes)
            ->pluck('tie_nombre', 'tie_codigo')
            ->map(fn ($name) => trim((string) $name))
            ->all();
    }

    private function normalizeStoreName(string $countryCode, string $storeCode, ?string $storeName): string
    {
        $domicilioCode = config("inventory.domicilio_store_by_country.{$countryCode}");

        if ($domicilioCode && trim($storeCode) === trim((string) $domicilioCode)) {
            return 'Domicilio';
        }

        return trim((string) ($storeName ?: $storeCode));
    }
}
