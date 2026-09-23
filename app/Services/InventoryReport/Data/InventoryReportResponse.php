<?php

namespace App\Services\InventoryReport\Data;

final readonly class InventoryReportResponse
{
    /**
     * @param  array<int, array{code: string, store: string, size: string, quantity: int|float, sale_price: int|float|null}>  $rows
     * @param  array<int, string>  $returnedCodes
     * @param  array<int, string>  $notReturnedCodes
     * @param  array<int, string>  $explicitNotFoundCodes
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public array $rows,
        public array $returnedCodes,
        public array $notReturnedCodes,
        public array $explicitNotFoundCodes = [],
        public array $warnings = [],
    ) {}
}
