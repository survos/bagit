<?php

declare(strict_types=1);

namespace Survos\BagIt;

/** BagIt manifest parsing, including the legacy checksum-tool binary marker. */
final class Manifest
{
    public const array ALGORITHMS = ['md5', 'sha1', 'sha256', 'sha512'];

    /** @return array<string, string> Relative path => lowercase digest. */
    public static function read(string $file, string $algorithm): array
    {
        self::algorithm($algorithm);
        $length = strlen(hash($algorithm, ''));
        $entries = [];
        foreach (self::lines($file) as $line) {
            if (!preg_match('/^([a-fA-F0-9]{'.$length.'})[ \t]+\*?(.+)$/Du', $line, $match)) {
                throw new IntegrityException('Malformed manifest entry in '.$file);
            }
            // Decode only the three escapes defined by BagIt, once; %252F is a literal %2F.
            if (preg_match('/%(?!25|0[aAdD])/', $match[2])) {
                throw new IntegrityException('Invalid manifest path escape in '.$file);
            }
            $path = str_ireplace(['%0A', '%0D', '%25'], ["\n", "\r", '%'], $match[2]);
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
        sort($paths, SORT_STRING);
        $stream = fopen($file, 'xb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot create manifest: '.$file);
        }
        try {
            foreach ($paths as $path) {
                Filesystem::path($path);
                $encoded = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $path);
                $line = Filesystem::digest($root.'/'.$path, $algorithm).'  '.$encoded."\n";
                if (strlen($line) !== fwrite($stream, $line)) {
                    throw new \RuntimeException('Incomplete manifest write: '.$file);
                }
            }
        } finally {
            fclose($stream);
        }
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
