<?php

namespace App\Services\InventoryReport\Adapters;

use App\Services\InventoryReport\Contracts\InventoryReportAdapter;
use App\Services\InventoryReport\Data\InventoryReportResponse;
use InvalidArgumentException;

final class HondurasInventoryReportAdapter extends AbstractInventoryReportAdapter implements InventoryReportAdapter
{
    public function payload(int $countryId, string $countryCode, array $productCodes, array $storeCodes): array
    {
        return [
            'Pais' => (string) $countryId,
            'Tiendas' => "'".implode(',', array_map(static fn (mixed $store): string => trim((string) $store), $storeCodes))."'",
            'Codigos' => $this->quotedList($productCodes),
        ];
    }

    public function normalize(array $payload, string $countryCode, array $requestedCodes): InventoryReportResponse
    {
        if (! array_key_exists('datos', $payload)) {
            throw new InvalidArgumentException('La respuesta de Honduras no contiene la propiedad datos.');
        }

        return $this->normalizeRows($payload['datos'], $requestedCodes);
    }
}
