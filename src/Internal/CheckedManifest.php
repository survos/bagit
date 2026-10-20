<?php

declare(strict_types=1);

namespace Survos\BagIt\Internal;

use whikloj\BagItTools\AbstractManifest;
use whikloj\BagItTools\Bag;

/** @internal Delegate checksum I/O to BagItTools after path validation; also bridge 0.96 literal paths. */
final class CheckedManifest extends AbstractManifest
{
    /** @param array<string, string> $hashes Paths have already passed the integration's safety checks. */
    public function __construct(Bag $bag, string $algorithm, string $filename, array $hashes)
    {
        parent::__construct($bag, $algorithm, $filename);
        $this->hashes = $hashes;
    }
}
