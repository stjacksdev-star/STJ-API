<?php

namespace App\Services\InventoryReport\Adapters;

use App\Services\InventoryReport\Data\InventoryReportResponse;
use InvalidArgumentException;

abstract class AbstractInventoryReportAdapter
{
    /**
     * @param  array<int, string>  $requestedCodes
     */
    protected function normalizeRows(mixed $rows, array $requestedCodes): InventoryReportResponse
    {
        if (! is_array($rows)) {
            throw new InvalidArgumentException('La API de inventario no devolvio una coleccion de datos valida.');
        }

        $requested = array_values(array_unique(array_filter(array_map(
            static fn (mixed $code): string => trim((string) $code),
            $requestedCodes,
        ))));
        $requestedLookup = array_fill_keys($requested, true);
        $normalized = [];
        $returned = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (! is_array($row)) {
                $warnings[] = "Fila {$index} ignorada: no es un objeto.";

                continue;
            }

            $row = array_change_key_case($row, CASE_LOWER);
            $code = trim((string) ($row['estilo'] ?? $row['codigo'] ?? $row['code'] ?? ''));
            $store = trim((string) ($row['tienda'] ?? $row['store'] ?? ''));
            $size = trim((string) ($row['talla'] ?? $row['size'] ?? ''));
            $quantity = $row['existencia'] ?? $row['cantidad'] ?? $row['quantity'] ?? null;
            $price = $row['precio'] ?? $row['preciovta'] ?? $row['sale_price'] ?? null;

            if ($code === '' || $store === '' || $size === '' || ! is_numeric($quantity)) {
                $warnings[] = "Fila {$index} ignorada: estilo, tienda, talla o existencia invalida.";

                continue;
            }
            if (! isset($requestedLookup[$code])) {
                $warnings[] = "Fila {$index} ignorada: el estilo {$code} no fue solicitado.";

                continue;
            }
            if ($price !== null && $price !== '' && ! is_numeric($price)) {
                $warnings[] = "Fila {$index} ignorada: precio invalido para {$code}.";

                continue;
            }

            $quantity = (float) $quantity;
            $normalized[] = [
                'code' => $code,
                'store' => $store,
                'size' => $size,
                'quantity' => floor($quantity) === $quantity ? (int) $quantity : $quantity,
                'sale_price' => $price === null || $price === '' ? null : (float) $price,
            ];
            $returned[$code] = true;
        }

        $returnedCodes = array_values(array_keys($returned));

        return new InventoryReportResponse(
            rows: $normalized,
            returnedCodes: $returnedCodes,
            notReturnedCodes: array_values(array_diff($requested, $returnedCodes)),
            explicitNotFoundCodes: [],
            warnings: $warnings,
        );
    }

    /** @param array<int, string> $values */
    protected function quotedList(array $values): string
    {
        return collect($values)
            ->map(static fn (mixed $value): string => "'".str_replace("'", "''", trim((string) $value))."'")
            ->implode(',');
    }
}
