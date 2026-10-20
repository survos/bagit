<?php

declare(strict_types=1);

namespace Survos\BagIt;

final readonly class MetadataEntry
{
    public function __construct(public string $label, public string $value)
    {
    }
}
