<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

class LocalInventoryProvider
{
    public function fetchProductListAvailability(int $countryId, string $storeCode, array $productCodes): array
    {
        try {
            $rows = DB::table('stj_inventario as i')
                ->where('i.inv_pais', $countryId)
                ->where('i.inv_tienda', $storeCode)
                ->whereIn('i.inv_codigo', $productCodes)
                ->where('i.inv_cantidad', '>', 0)
                ->select([
                    'i.inv_codigo as estilo',
                    'i.inv_talla as talla',
                    'i.inv_cantidad as existencia',
                    'i.inv_tienda as codTienda',
                ])
                ->get()
                ->map(fn ($row) => [
                    'estilo' => (string) $row->estilo,
                    'talla' => trim((string) $row->talla),
                    'existencia' => (int) $row->existencia,
                    'codTienda' => trim((string) $row->codTienda),
                ])
                ->all();

            return [
                'ok' => true,
                'rows' => $rows,
                'source' => 'local_inventory',
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'rows' => [],
                'source' => 'local_inventory',
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function fetchProductDetailAvailability(int $countryId, array $storeCodes, string $productCode): array
    {
        try {
            $rows = DB::table('stj_inventario as i')
                ->join('stj_tiendas as t', 't.tie_codigo', '=', 'i.inv_tienda')
                ->where('i.inv_pais', $countryId)
                ->where('i.inv_codigo', $productCode)
                ->whereIn('i.inv_tienda', $storeCodes)
                ->select([
                    'i.inv_codigo as estilo',
                    'i.inv_talla as talla',
                    'i.inv_cantidad as existencia',
                    'i.inv_tienda as codTienda',
                    't.tie_nombre as tienda',
                ])
                ->get()
                ->map(fn ($row) => [
                    'estilo' => (string) $row->estilo,
                    'talla' => trim((string) $row->talla),
                    'existencia' => (int) $row->existencia,
                    'codTienda' => trim((string) $row->codTienda),
                    'tienda' => trim((string) $row->tienda),
                ])
                ->all();

            return [
                'ok' => true,
                'rows' => $rows,
                'source' => 'local_inventory',
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'rows' => [],
                'source' => 'local_inventory',
                'error' => $exception->getMessage(),
            ];
        }
    }
}
