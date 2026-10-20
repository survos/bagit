<?php

declare(strict_types=1);

namespace Survos\BagIt\Source;

use Survos\BagIt\Filesystem;
use Survos\BagIt\IntegrityException;
use Symfony\Component\Finder\Finder;

/** An explicit payload selection; never use a filtered Finder to assert whole-bag completeness. */
final class FinderSource implements FileSource
{
    private readonly LocalDirectorySource $local;
    /** @var list<string> */
    private array $paths = [];

    public function __construct(Finder $finder, string $root)
    {
        $this->local = new LocalDirectorySource($root);
        $prefix = rtrim($this->local->directory, '/').'/';
        foreach ($finder as $file) {
            if ($file->isLink() || !$file->isFile()) {
                throw new IntegrityException('Finder must select regular files only: '.$file->getPathname());
            }
            $absolute = $file->getPathname();
            if (!str_starts_with($absolute, '/')) {
                $absolute = getcwd().'/'.$absolute;
            }
            // Check lexical ancestors too: realpath alone would conceal symlink traversal.
            $current = '';
            $enteredRoot = false;
            foreach (explode('/', $absolute) as $part) {
                if ('' === $part) {
                    continue;
                }
                $current .= '/'.$part;
                $resolved = realpath($current);
                if (is_link($current) && ($enteredRoot || $resolved === $this->local->directory || (false !== $resolved && str_starts_with($resolved, $prefix)))) {
                    throw new IntegrityException('Finder selected a symlink path: '.$absolute);
                }
                if (realpath($current) === $this->local->directory) {
                    $enteredRoot = true;
                }
            }
            $real = $file->getRealPath();
            if (false === $real || !str_starts_with($real, $prefix)) {
                throw new IntegrityException('Finder file lies outside source root: '.$absolute);
            }
            $path = substr($real, strlen($prefix));
            Filesystem::path($path);
            if (in_array($path, $this->paths, true)) {
                throw new IntegrityException('Duplicate Finder path: '.$path);
            }
            $this->paths[] = $path;
        }
        sort($this->paths, SORT_STRING);
    }

    public function files(): iterable
    {
        return $this->paths;
    }

    public function readStream(string $path): mixed
    {
        if (!in_array($path, $this->paths, true)) {
            throw new IntegrityException('File was not selected by Finder: '.$path);
        }

        return $this->local->readStream($path);
    }
}
