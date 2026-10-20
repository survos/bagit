<?php

declare(strict_types=1);

namespace Survos\BagIt\Source;

/** A stable snapshot of regular files. Implementations must reject links and unsafe paths. */
interface FileSource
{
    /** @return iterable<string> All file paths relative to this source, with no duplicates. */
    public function files(): iterable;

    /** @return resource Readable binary stream at offset zero; the caller closes it. */
    public function readStream(string $path): mixed;
}
