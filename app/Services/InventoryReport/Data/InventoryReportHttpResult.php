<?php

namespace App\Services\InventoryReport\Data;

final readonly class InventoryReportHttpResult
{
    public function __construct(
        public InventoryReportResponse $response,
        public int $httpStatus,
        public int $durationMs,
        public string $endpoint,
        public string $adapter,
    ) {}
}
