<?php

declare(strict_types=1);

namespace Survos\BagIt\Source;

use Survos\BagIt\Filesystem;
use Survos\BagIt\IntegrityException;

final readonly class LocalDirectorySource implements FileSource
{
    public string $directory;

    public function __construct(string $directory)
    {
        $this->directory = Filesystem::root($directory);
    }

    public function files(): iterable
    {
        return Filesystem::files($this->directory);
    }

    public function readStream(string $path): mixed
    {
        Filesystem::path($path);
        $file = $this->directory;
        foreach (explode('/', $path) as $part) {
            $file .= '/'.$part;
            if (is_link($file)) {
                throw new IntegrityException('Symlink is not allowed: '.$path);
            }
        }
        if (!is_file($file)) {
            throw new IntegrityException('Not a regular file: '.$path);
        }
        $stream = fopen($file, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot read: '.$path);
        }

        return $stream;
    }
}
