<?php

namespace App\Services\InventoryReport\Adapters;

use App\Services\InventoryReport\Contracts\InventoryReportAdapter;
use App\Services\InventoryReport\Data\InventoryReportResponse;
use InvalidArgumentException;

final class ElSalvadorInventoryReportAdapter extends AbstractInventoryReportAdapter implements InventoryReportAdapter
{
    public function payload(int $countryId, string $countryCode, array $productCodes, array $storeCodes): array
    {
        return [
            'Pais' => (string) $countryId,
            'Codigos' => $this->quotedList($productCodes),
            'Tiendas' => $this->quotedList($storeCodes),
        ];
    }

    public function normalize(array $payload, string $countryCode, array $requestedCodes): InventoryReportResponse
    {
        if (! array_key_exists('datos', $payload)) {
            throw new InvalidArgumentException('La respuesta de El Salvador no contiene la propiedad datos.');
        }

        return $this->normalizeRows($payload['datos'], $requestedCodes);
    }
}
