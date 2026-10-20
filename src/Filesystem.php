<?php

declare(strict_types=1);

namespace Survos\BagIt;

/** @internal Local filesystem operations; callers must keep input trees immutable. */
final class Filesystem
{
    public static function path(string $path): void
    {
        if ('' === $path || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':') || 1 !== preg_match('//u', $path)) {
            throw new IntegrityException('Unsafe or non-UTF-8 relative path: '.$path);
        }
        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                throw new IntegrityException('Unsafe relative path: '.$path);
            }
        }
    }

    public static function root(string $directory): string
    {
        if (is_link($directory) || !is_dir($directory) || false === ($root = realpath($directory))) {
            throw new IntegrityException('Not a regular directory: '.$directory);
        }

        return $root;
    }

    /** @return list<string> Sorted regular files relative to root. Reject links even in unused tag directories. */
    public static function files(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen(rtrim($root, '/')) + 1);
            self::path($relative);
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new IntegrityException('Symlink or special file is not allowed: '.$relative);
            }
            if ($entry->isFile()) {
                $files[] = $relative;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    public static function digest(string $file, string $algorithm): string
    {
        $digest = hash_file($algorithm, $file);
        if (false === $digest) {
            throw new \RuntimeException('Cannot hash file: '.$file);
        }

        return $digest;
    }

    public static function write(string $file, string $contents): void
    {
        if (strlen($contents) !== file_put_contents($file, $contents)) {
            throw new \RuntimeException('Incomplete write: '.$file);
        }
    }

    /** Publish under a parent-directory lock shared by this library's writers. */
    public static function publish(string $destination, \Closure $build): Verification
    {
        $parent = self::root(dirname($destination));
        $name = basename($destination);
        self::path($name);
        $destination = $parent.'/'.$name;
        // Lock the directory itself: no persistent lock-file clutter or unlink races (POSIX).
        $lock = fopen($parent, 'r');
        if (false === $lock) {
            throw new \RuntimeException('Cannot open publication lock: '.$parent);
        }
        $stage = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire publication lock: '.$parent);
            }
            self::requireAbsent($destination);
            $stage = $parent.'/.bagit-'.bin2hex(random_bytes(12));
            if (!mkdir($stage, 0700)) {
                throw new \RuntimeException('Cannot create staging directory: '.$stage);
            }
            $report = $build($stage);
            self::requireAbsent($destination);
            if (!rename($stage, $destination)) {
                throw new \RuntimeException('Cannot publish bag: '.$destination);
            }
            $stage = null;

            return new Verification($destination, $report->version, $report->payloadFiles, $report->payloadBytes, $report->payloadAlgorithms);
        } finally {
            if (null !== $stage) {
                self::remove($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function requireAbsent(string $destination): void
    {
        clearstatcache(true, $destination);
        if (file_exists($destination) || is_link($destination)) {
            throw new \RuntimeException('Destination already exists: '.$destination);
        }
    }

    private static function remove(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
