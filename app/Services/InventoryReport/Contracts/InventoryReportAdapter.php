<?php

namespace App\Services\InventoryReport\Contracts;

use App\Services\InventoryReport\Data\InventoryReportResponse;

interface InventoryReportAdapter
{
    /**
     * @param  array<int, string>  $productCodes
     * @param  array<int, string>  $storeCodes
     * @return array<string, string>
     */
    public function payload(int $countryId, string $countryCode, array $productCodes, array $storeCodes): array;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $requestedCodes
     */
    public function normalize(array $payload, string $countryCode, array $requestedCodes): InventoryReportResponse;
}
