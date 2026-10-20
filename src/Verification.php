<?php

declare(strict_types=1);

namespace Survos\BagIt;

final readonly class Verification
{
    /** @param list<string> $payloadAlgorithms */
    public function __construct(
        public string $directory,
        public string $version,
        public int $payloadFiles,
        public int $payloadBytes,
        public array $payloadAlgorithms,
    ) {
    }
}
