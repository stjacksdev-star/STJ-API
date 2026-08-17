<?php

namespace App\Services\Inventory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class InventorySynchronizer
{
    public function __construct(private readonly InventorySyncClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $countryCode = null, ?int $batchSize = null, bool $dryRun = false): array
    {
        $control = $this->eligibleCountries($countryCode)->first();

        if ($control === null) {
            return [
                'ok' => true,
                'processed' => false,
                'message' => 'No hay paises activos y habilitados pendientes de sincronizacion.',
            ];
        }

        $limit = $batchSize ?? (int) $control->isc_batch_size;
        $limit = max(1, min(500, $limit));
        $cursor = max(0, (int) $control->isc_next_product_id);
        $products = $this->products((int) $control->pai_id, $cursor, $limit + 1);
        $hasMore = $products->count() > $limit;
        $products = $products->take($limit)->values();
        $stores = $this->stores((int) $control->pai_id, (string) $control->pai_codigo);

        $summary = [
            'ok' => true,
            'processed' => false,
            'dryRun' => $dryRun,
            'countryId' => (int) $control->pai_id,
            'countryCode' => (string) $control->pai_codigo,
            'countryName' => (string) $control->pai_nombre,
            'endpointProfile' => (string) $control->isc_endpoint_profile,
            'cursor' => $cursor,
            'batchSize' => $limit,
            'products' => $products->count(),
            'stores' => $stores->count(),
            'rows' => 0,
            'cycleCompleted' => false,
            'errors' => [],
        ];

        if ($stores->isEmpty()) {
            return $this->fail($control, $summary, 'El pais no tiene tiendas con tie_productos = 1.', $dryRun);
        }

        if ($products->isEmpty()) {
            $summary['processed'] = true;
            $summary['cycleCompleted'] = true;
            $summary['message'] = 'El pais no tiene mas productos activos; el cursor fue reiniciado.';

            if (! $dryRun) {
                DB::table('stj_inventory_sync_countries')
                    ->where('isc_id', $control->isc_id)
                    ->update([
                        'isc_next_product_id' => 0,
                        'isc_cycle_number' => DB::raw('isc_cycle_number + 1'),
                        'isc_cycle_completed_at' => now(),
                        'isc_cycle_started_at' => null,
                        'isc_last_started_at' => now(),
                        'isc_last_success_at' => now(),
                        'isc_last_error' => null,
                        'isc_consecutive_failures' => 0,
                        'isc_last_batch_products' => 0,
                        'isc_last_batch_stores' => $stores->count(),
                        'isc_last_batch_rows' => 0,
                    ]);
            }

            return $summary;
        }

        if ($dryRun) {
            $summary['message'] = 'Vista previa generada; no se consultaron endpoints ni se modifico inventario.';

            return $summary;
        }

        DB::table('stj_inventory_sync_countries')
            ->where('isc_id', $control->isc_id)
            ->update([
                'isc_last_started_at' => now(),
                'isc_cycle_started_at' => $control->isc_cycle_started_at ?: now(),
            ]);

        $codes = $products->pluck('pro_codigo')->map(fn ($code) => trim((string) $code))->all();

        foreach ($stores as $store) {
            $storeCode = trim((string) $store->tie_codigo);
            $response = $this->client->fetch(
                (int) $control->pai_id,
                (string) $control->isc_endpoint_profile,
                $storeCode,
                $codes,
            );

            if (! $response['ok']) {
                $summary['errors'][] = "Tienda {$storeCode}: ".($response['error'] ?? 'error desconocido');

                continue;
            }

            $this->replaceStoreBatch((int) $control->pai_id, $storeCode, $codes, $response['rows']);
            $summary['rows'] += count($response['rows']);
        }

        if ($summary['errors'] !== []) {
            return $this->fail($control, $summary, implode(' | ', $summary['errors']));
        }

        $lastProductId = (int) $products->last()->pro_id;
        $summary['processed'] = true;
        $summary['cycleCompleted'] = ! $hasMore;
        $summary['nextCursor'] = $hasMore ? $lastProductId : 0;
        $summary['message'] = $hasMore
            ? 'Lote sincronizado correctamente.'
            : 'Lote sincronizado y ciclo del pais completado.';

        $updates = [
            'isc_next_product_id' => $summary['nextCursor'],
            'isc_last_success_at' => now(),
            'isc_last_error' => null,
            'isc_consecutive_failures' => 0,
            'isc_last_batch_products' => $products->count(),
            'isc_last_batch_stores' => $stores->count(),
            'isc_last_batch_rows' => $summary['rows'],
        ];

        if ($summary['cycleCompleted']) {
            $updates['isc_cycle_number'] = DB::raw('isc_cycle_number + 1');
            $updates['isc_cycle_completed_at'] = now();
            $updates['isc_cycle_started_at'] = null;
        }

        DB::table('stj_inventory_sync_countries')
            ->where('isc_id', $control->isc_id)
            ->update($updates);

        return $summary;
    }

    private function eligibleCountries(?string $countryCode): Builder
    {
        $configuredProfiles = collect((array) config('inventory.sync.endpoints', []))
            ->filter(fn (mixed $url) => trim((string) $url) !== '')
            ->keys()
            ->all();

        return DB::table('stj_inventory_sync_countries as sync')
            ->join('stj_paises as country', 'country.pai_id', '=', 'sync.isc_country_id')
            ->where('country.pai_estado', 'ACTIVO')
            ->where('sync.isc_enabled', 1)
            ->whereIn('sync.isc_endpoint_profile', $configuredProfiles)
            ->when(
                $countryCode !== null && trim($countryCode) !== '',
                fn (Builder $query) => $query->whereRaw('UPPER(country.pai_codigo) = ?', [strtoupper(trim($countryCode))]),
            )
            ->orderByRaw('CASE WHEN sync.isc_last_success_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('sync.isc_last_success_at')
            ->orderBy('sync.isc_id')
            ->select(['sync.*', 'country.pai_id', 'country.pai_codigo', 'country.pai_nombre']);
    }

    private function products(int $countryId, int $cursor, int $limit)
    {
        return DB::table('stj_productos as product')
            ->join('stj_producto_pais as country_product', 'country_product.ppa_producto', '=', 'product.pro_id')
            ->where('country_product.ppa_pais', $countryId)
            ->where('country_product.ppa_estado', 'ACTIVO')
            ->where('product.pro_estatus', 'ACTIVO')
            ->where('product.pro_id', '>', $cursor)
            ->whereNotNull('product.pro_codigo')
            ->where('product.pro_codigo', '<>', '')
            ->orderBy('product.pro_id')
            ->limit($limit)
            ->get(['product.pro_id', 'product.pro_codigo']);
    }

    private function stores(int $countryId, string $countryCode)
    {
        $homeDeliveryStore = trim((string) config(
            'inventory.domicilio_store_by_country.'.strtolower($countryCode),
            '',
        ));

        return DB::table('stj_tiendas')
            ->where('tie_pais', $countryId)
            ->where(function (Builder $query) use ($homeDeliveryStore) {
                $query->where('tie_productos', 1);

                if ($homeDeliveryStore !== '') {
                    $query->orWhere('tie_codigo', $homeDeliveryStore);
                }
            })
            ->whereNotNull('tie_codigo')
            ->where('tie_codigo', '<>', '')
            ->orderBy('tie_id')
            ->get(['tie_id', 'tie_codigo', 'tie_nombre']);
    }

    /**
     * @param  array<int, string>  $codes
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function replaceStoreBatch(int $countryId, string $storeCode, array $codes, array $rows): void
    {
        DB::transaction(function () use ($countryId, $storeCode, $codes, $rows): void {
            $timestamp = now();

            DB::table('stj_inventario')
                ->where('inv_pais', $countryId)
                ->where('inv_tienda', $storeCode)
                ->whereIn('inv_codigo', $codes)
                ->update([
                    'inv_cantidad' => 0,
                    'inv_actualizado' => $timestamp,
                ]);

            $inventoryRows = collect($rows)
                ->map(fn (array $row) => [
                    'inv_pais' => $countryId,
                    'inv_tienda' => $storeCode,
                    'inv_codigo' => $row['code'],
                    'inv_talla' => $row['size'],
                    'inv_cantidad' => $row['quantity'],
                    'inv_registro' => $timestamp,
                    'inv_actualizado' => $timestamp,
                ])
                ->all();

            if ($inventoryRows !== []) {
                DB::table('stj_inventario')->upsert(
                    $inventoryRows,
                    ['inv_tienda', 'inv_codigo', 'inv_talla', 'inv_pais'],
                    ['inv_cantidad', 'inv_actualizado'],
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function fail(object $control, array $summary, string $error, bool $dryRun = false): array
    {
        $summary['ok'] = false;
        $summary['message'] = $error;

        if (! $dryRun) {
            DB::table('stj_inventory_sync_countries')
                ->where('isc_id', $control->isc_id)
                ->update([
                    'isc_last_error_at' => now(),
                    'isc_last_error' => mb_substr($error, 0, 65000),
                    'isc_consecutive_failures' => DB::raw('isc_consecutive_failures + 1'),
                ]);
        }

        return $summary;
    }
}
