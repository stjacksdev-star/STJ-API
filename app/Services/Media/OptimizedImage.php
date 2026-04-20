<?php

namespace App\Services\Media;

class OptimizedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly string $mime,
    ) {
    }
}
