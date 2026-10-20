<?php

declare(strict_types=1);

namespace Survos\BagIt\Source;

use League\Flysystem\FilesystemReader;
use Survos\BagIt\Filesystem;
use Survos\BagIt\IntegrityException;

/** Read-only bridge; credentials, transfers and remote publication policy belong to the application. */
final readonly class FlysystemSource implements FileSource
{
    public function __construct(private FilesystemReader $filesystem, private string $prefix = '')
    {
        if ('' !== $prefix) {
            Filesystem::path($prefix);
        }
    }

    public function files(): iterable
    {
        $prefix = '' === $this->prefix ? '' : $this->prefix.'/';
        foreach ($this->filesystem->listContents($this->prefix, true) as $entry) {
            $path = $entry->path();
            if (!str_starts_with($path, $prefix)) {
                throw new IntegrityException('Flysystem listing escaped its prefix: '.$path);
            }
            $relative = substr($path, strlen($prefix));
            Filesystem::path($relative);
            if ($entry->isFile()) {
                yield $relative;
            } elseif (!$entry->isDir()) {
                throw new IntegrityException('Unsupported Flysystem entry: '.$path);
            }
        }
    }

    public function readStream(string $path): mixed
    {
        Filesystem::path($path);

        return $this->filesystem->readStream(('' === $this->prefix ? '' : $this->prefix.'/').$path);
    }
}
