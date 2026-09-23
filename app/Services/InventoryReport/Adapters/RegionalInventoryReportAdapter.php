<?php

namespace App\Services\InventoryReport\Adapters;

use App\Services\InventoryReport\Contracts\InventoryReportAdapter;
use App\Services\InventoryReport\Data\InventoryReportResponse;
use InvalidArgumentException;

final class RegionalInventoryReportAdapter extends AbstractInventoryReportAdapter implements InventoryReportAdapter
{
    private const RESPONSE_KEYS = [
        'GT' => 'gt_datos',
        'CR' => 'cr_datos',
        'PA' => 'pa_datos',
    ];

    private const REQUEST_KEYS = [
        'GT' => 'EstilosGT',
        'CR' => 'EstilosCR',
        'PA' => 'EstilosPA',
    ];

    public function payload(int $countryId, string $countryCode, array $productCodes, array $storeCodes): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (! isset(self::REQUEST_KEYS[$countryCode])) {
            throw new InvalidArgumentException("El adaptador regional no soporta el pais {$countryCode}.");
        }

        $payload = [
            'Pais' => (string) $countryId,
            'Estilos' => '',
            'EstilosSV' => '',
            'EstilosGT' => '',
            'EstilosCR' => '',
            'EstilosPA' => '',
        ];
        $payload[self::REQUEST_KEYS[$countryCode]] = $this->quotedList($productCodes);

        return $payload;
    }

    public function normalize(array $payload, string $countryCode, array $requestedCodes): InventoryReportResponse
    {
        $countryCode = strtoupper(trim($countryCode));
        $key = self::RESPONSE_KEYS[$countryCode] ?? null;
        if ($key === null) {
            throw new InvalidArgumentException("El adaptador regional no soporta el pais {$countryCode}.");
        }
        if (! array_key_exists($key, $payload)) {
            throw new InvalidArgumentException("La respuesta regional no contiene la propiedad {$key}.");
        }

        return $this->normalizeRows($payload[$key], $requestedCodes);
    }
}
