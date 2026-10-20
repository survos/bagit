<?php

declare(strict_types=1);

namespace Survos\BagIt;

final readonly class Diagnostic
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $path = null,
        public ?string $manifest = null,
    ) {
    }
}
