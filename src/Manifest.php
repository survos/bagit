<?php

declare(strict_types=1);

namespace Survos\BagIt;

/** BagIt manifest parsing, including the legacy checksum-tool binary marker. */
final class Manifest
{
    public const array ALGORITHMS = ['md5', 'sha1', 'sha256', 'sha512'];

    /** @return array<string, string> Relative path => lowercase digest. */
    public static function read(string $file, string $algorithm, string $version = '1.0'): array
    {
        self::algorithm($algorithm);
        if (!in_array($version, ['0.9', '0.96', '0.97', '1.0'], true)) {
            throw new IntegrityException('Unsupported manifest version: '.$version);
        }
        $length = strlen(hash($algorithm, ''));
        $entries = [];
        foreach (self::lines($file) as $line) {
            if (!preg_match('/^([a-fA-F0-9]{'.$length.'})[ \t]+\*?(.+)$/Du', $line, $match)) {
                throw new IntegrityException('Malformed manifest entry in '.$file);
            }
            // Decode only the three escapes defined by BagIt, once; %252F is a literal %2F.
            if ('0.96' !== $version && preg_match('/%(?!25|0[aAdD])/', $match[2])) {
                throw new IntegrityException('Invalid manifest path escape in '.$file);
            }
            $path = '0.96' === $version ? $match[2] : str_ireplace(['%0A', '%0D', '%25'], ["\n", "\r", '%'], $match[2]);
            Filesystem::path($path);
            if (isset($entries[$path])) {
                throw new IntegrityException('Duplicate manifest path: '.$path);
            }
            $entries[$path] = strtolower($match[1]);
        }

        return $entries;
    }

    /** @param list<string> $paths */
    public static function write(string $root, string $file, array $paths, string $algorithm): void
    {
        self::algorithm($algorithm);
        $root = Filesystem::root($root);
        $files = array_fill_keys(Filesystem::files($root), true);
        Internal\Inspector::version($root);
        Internal\Inspector::requireOffline($root);
        if (realpath(dirname($file)) !== $root || file_exists($file) || is_link($file)) {
            throw new \InvalidArgumentException('Manifest must be a new file in the bag root.');
        }
        Filesystem::path(basename($file));
        sort($paths, SORT_STRING);
        $hashes = [];
        foreach ($paths as $path) {
            Filesystem::path($path);
            if (!isset($files[$path]) || isset($hashes[$path])) {
                throw new IntegrityException('Missing or duplicate manifest source: '.$path);
            }
            $hashes[$path] = '';
        }
        (new Internal\CheckedManifest(\whikloj\BagItTools\Bag::load($root), $algorithm, basename($file), $hashes))->update();
    }

    public static function algorithm(string $algorithm): void
    {
        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new IntegrityException('Unsupported checksum algorithm: '.$algorithm);
        }
    }

    /** @return \Generator<int, string> Reads LF, CRLF and CR without loading the file. */
    public static function lines(string $file): \Generator
    {
        $stream = fopen($file, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot read: '.$file);
        }
        $buffer = '';
        $skipLf = false;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if (false === $chunk) {
                    throw new \RuntimeException('Cannot read: '.$file);
                }
                $buffer .= $chunk;
                while ('' !== $buffer) {
                    if ($skipLf) {
                        if ("\n" === $buffer[0]) {
                            $buffer = substr($buffer, 1);
                        }
                        $skipLf = false;
                    }
                    $offset = strcspn($buffer, "\r\n");
                    if ($offset === strlen($buffer)) {
                        break;
                    }
                    $line = substr($buffer, 0, $offset);
                    $skipLf = "\r" === $buffer[$offset];
                    $buffer = substr($buffer, $offset + 1);
                    yield $line;
                }
            }
            if ('' !== $buffer) {
                yield $buffer;
            }
        } finally {
            fclose($stream);
        }
    }
}
