<?php

namespace App\Services\InventoryReport;

use App\Services\InventoryReport\Adapters\ElSalvadorInventoryReportAdapter;
use App\Services\InventoryReport\Adapters\HondurasInventoryReportAdapter;
use App\Services\InventoryReport\Adapters\RegionalInventoryReportAdapter;
use App\Services\InventoryReport\Contracts\InventoryReportAdapter;
use InvalidArgumentException;

class InventoryReportAdapterResolver
{
    public function __construct(
        private readonly ElSalvadorInventoryReportAdapter $elSalvador,
        private readonly RegionalInventoryReportAdapter $regional,
        private readonly HondurasInventoryReportAdapter $honduras,
    ) {}

    public function resolve(string $adapter): InventoryReportAdapter
    {
        return match (strtolower(trim($adapter))) {
            'sv' => $this->elSalvador,
            'regional' => $this->regional,
            'hn' => $this->honduras,
            default => throw new InvalidArgumentException("Adaptador de reporte no soportado: {$adapter}."),
        };
    }
}
