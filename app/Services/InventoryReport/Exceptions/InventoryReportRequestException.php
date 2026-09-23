<?php

namespace App\Services\InventoryReport\Exceptions;

use RuntimeException;
use Throwable;

class InventoryReportRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $durationMs = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
