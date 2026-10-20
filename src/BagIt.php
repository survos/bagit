<?php

declare(strict_types=1);

namespace Survos\BagIt;

use Survos\BagIt\Source\FileSource;
use Survos\BagIt\Source\LocalDirectorySource;

final class BagIt
{
    /** Copy an arbitrary payload directory into a new, verified BagIt 1.0 directory.
     * @param array<string, string|list<string>> $metadata ordered bag-info fields; repeated values are preserved
     */
    public function create(string $payloadDirectory, string $destination, array $metadata = [], string $algorithm = 'sha256'): Verification
    {
        $source = new LocalDirectorySource($payloadDirectory);
        $this->outside($source->directory, $destination);

        return $this->createFrom($source, $destination, $metadata, $algorithm);
    }

    /** @param array<string, string|list<string>> $metadata */
    public function createFrom(FileSource $source, string $destination, array $metadata = [], string $algorithm = 'sha256'): Verification
    {
        Manifest::algorithm($algorithm);
        if ($source instanceof LocalDirectorySource) {
            $this->outside($source->directory, $destination);
        }
        $files = $this->inventory($source);

        return Filesystem::publish($destination, function (string $stage) use ($source, $files, $metadata, $algorithm): Verification {
            if (!mkdir($stage.'/data', 0700)) {
                throw new \RuntimeException('Cannot create payload directory.');
            }
            $paths = [];
            $bytes = 0;
            foreach ($files as $file) {
                $this->copyStream($source, $file, $stage.'/data/'.$file);
                $paths[] = 'data/'.$file;
                $size = filesize($stage.'/data/'.$file);
                if (false === $size) {
                    throw new \RuntimeException('Cannot determine payload size: '.$file);
                }
                $bytes += $size;
            }
            Filesystem::write($stage.'/bagit.txt', "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n");
            $info = '';
            foreach ($metadata as $label => $values) {
                if (!is_string($label) || '' === $label || trim($label) !== $label || preg_match('/[:\r\n\x00]/', $label) || 1 !== preg_match('//u', $label) || 0 === strcasecmp($label, 'Payload-Oxum')) {
                    throw new \InvalidArgumentException('Invalid or reserved bag-info label.');
                }
                foreach (is_array($values) ? $values : [$values] as $value) {
                    if (!is_string($value) || preg_match('/[\r\n\x00]/', $value) || 1 !== preg_match('//u', $value)) {
                        throw new \InvalidArgumentException('Metadata values must be single-line UTF-8 strings.');
                    }
                    $info .= $label.': '.$value."\n";
                }
            }
            $info .= 'Payload-Oxum: '.$bytes.'.'.count($paths)."\n";
            Filesystem::write($stage.'/bag-info.txt', $info);
            $manifest = 'manifest-'.$algorithm.'.txt';
            Manifest::write($stage, $stage.'/'.$manifest, $paths, $algorithm);
            Manifest::write($stage, $stage.'/tagmanifest-'.$algorithm.'.txt', ['bagit.txt', 'bag-info.txt', $manifest], $algorithm);

            return $this->verify($stage);
        });
    }

    /** Verify every checksum and require each payload manifest to cover exactly all payload files. */
    public function verify(string $directory): Verification
    {
        $root = Filesystem::root($directory);
        $files = array_fill_keys(Filesystem::files($root), true);
        if (!isset($files['bagit.txt']) || !is_dir($root.'/data')) {
            throw new IntegrityException('Missing bagit.txt or data directory.');
        }
        $declaration = iterator_to_array(Manifest::lines($root.'/bagit.txt'));
        if (2 !== count($declaration) || !preg_match('/^BagIt-Version: (0\.9|0\.97|1\.0)$/D', $declaration[0], $match)
            || 0 !== strcasecmp($declaration[1], 'Tag-File-Character-Encoding: UTF-8')) {
            throw new IntegrityException('Unsupported or malformed BagIt declaration (requires 0.9, 0.97 or 1.0 and UTF-8).');
        }
        $version = $match[1];
        $payload = array_filter($files, static fn (string|int $path): bool => str_starts_with((string) $path, 'data/'), ARRAY_FILTER_USE_KEY);
        $manifests = [];
        $tags = [];
        foreach ($files as $path => $_) {
            $path = (string) $path;
            if (preg_match('/^(tag)?manifest-([^\/]+)\.txt$/D', $path, $match)) {
                Manifest::algorithm($match[2]);
                if ('' === $match[1]) {
                    $manifests[$path] = $match[2];
                } else {
                    $tags[$path] = $match[2];
                }
            }
        }
        if ([] === $manifests) {
            throw new IntegrityException('Missing payload manifest.');
        }
        foreach ($tags as $path => $algorithm) {
            $entries = Manifest::read($root.'/'.$path, $algorithm);
            foreach ($entries as $entry => $digest) {
                $entry = (string) $entry;
                if (str_starts_with($entry, 'data/') || isset($tags[$entry])) {
                    throw new IntegrityException('Forbidden tag manifest reference: '.$entry);
                }
                $this->check($root, $files, $entry, $algorithm, $digest);
            }
            if ([] !== array_diff_key($manifests, $entries)) {
                throw new IntegrityException('Tag manifest omits a payload manifest: '.$path);
            }
        }
        foreach ($manifests as $path => $algorithm) {
            $entries = Manifest::read($root.'/'.$path, $algorithm);
            foreach ($entries as $entry => $digest) {
                $entry = (string) $entry;
                if (!str_starts_with($entry, 'data/')) {
                    throw new IntegrityException('Manifest references a non-payload path: '.$entry);
                }
                $this->check($root, $files, $entry, $algorithm, $digest);
            }
            $unlisted = array_diff_key($payload, $entries);
            if ([] !== $unlisted) {
                throw new IntegrityException('Unlisted payload in '.$path.': '.array_key_first($unlisted));
            }
        }
        $bytes = 0;
        foreach ($payload as $path => $_) {
            $size = filesize($root.'/'.$path);
            if (false === $size) {
                throw new \RuntimeException('Cannot determine size: '.$path);
            }
            $bytes += $size;
        }
        if (isset($files['bag-info.txt'])) {
            $this->checkOxum($root.'/bag-info.txt', $bytes, count($payload));
        }
        if (isset($files['fetch.txt'])) {
            foreach (Manifest::lines($root.'/fetch.txt') as $line) {
                if (!preg_match('/^\S+:[^ \t]*[ \t]+(-|[0-9]+)[ \t]+(.+)$/Du', $line, $match)) {
                    throw new IntegrityException('Malformed fetch.txt entry.');
                }
                if (preg_match('/%(?!25|0[aAdD])/', $match[2])) {
                    throw new IntegrityException('Invalid fetch.txt path escape.');
                }
                $path = str_ireplace(['%0A', '%0D', '%25'], ["\n", "\r", '%'], $match[2]);
                Filesystem::path($path);
                if (!isset($payload[$path]) || ('-' !== $match[1] && (string) filesize($root.'/'.$path) !== $match[1])) {
                    throw new IntegrityException('Missing payload or incorrect length in fetch.txt: '.$path);
                }
            }
        }

        return new Verification($root, $version, count($payload), $bytes, array_values($manifests));
    }

    /** Copy a complete directory bag, verify the staged copy, and publish without overwriting. */
    public function restore(string $bagDirectory, string $destination): Verification
    {
        $source = new LocalDirectorySource($this->verify($bagDirectory)->directory);
        $this->outside($source->directory, $destination);

        return $this->restoreFrom($source, $destination);
    }

    /** Restore a complete bag source to local storage; stage and verify before publishing. */
    public function restoreFrom(FileSource $source, string $destination): Verification
    {
        if ($source instanceof LocalDirectorySource) {
            $this->outside($source->directory, $destination);
        }
        $files = $this->inventory($source);

        return Filesystem::publish($destination, function (string $stage) use ($source, $files): Verification {
            if (!mkdir($stage.'/data', 0700)) {
                throw new \RuntimeException('Cannot create payload directory.');
            }
            foreach ($files as $file) {
                $this->copyStream($source, $file, $stage.'/'.$file);
            }

            return $this->verify($stage);
        });
    }

    private function outside(string $source, string $destination): void
    {
        $parent = Filesystem::root(dirname($destination));
        if ($parent === $source || str_starts_with($parent, rtrim($source, '/').'/')) {
            throw new \InvalidArgumentException('Destination must be outside the source directory.');
        }
    }

    /** @return list<string> */
    private function inventory(FileSource $source): array
    {
        $paths = [];
        foreach ($source->files() as $path) {
            Filesystem::path($path);
            if (isset($paths[$path])) {
                throw new IntegrityException('Duplicate source path: '.$path);
            }
            $paths[$path] = $path;
        }
        $paths = array_values($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function copyStream(FileSource $source, string $path, string $destination): void
    {
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
            throw new \RuntimeException('Cannot create directory: '.$parent);
        }
        $input = $source->readStream($path);
        if (!is_resource($input) || 'stream' !== get_resource_type($input)) {
            throw new \RuntimeException('Source did not return a readable stream: '.$path);
        }
        try {
            $output = fopen($destination, 'xb');
            if (false === $output) {
                throw new \RuntimeException('Cannot create: '.$destination);
            }
            try {
                if (false === stream_copy_to_stream($input, $output) || '' !== fread($input, 1) || !fflush($output)) {
                    throw new \RuntimeException('Incomplete stream copy: '.$path);
                }
            } finally {
                fclose($output);
            }
        } finally {
            fclose($input);
        }
    }

    /** @param array<string, bool> $files */
    private function check(string $root, array $files, string $path, string $algorithm, string $digest): void
    {
        if (!isset($files[$path])) {
            throw new IntegrityException('Missing manifest-listed file: '.$path);
        }
        if (!hash_equals($digest, Filesystem::digest($root.'/'.$path, $algorithm))) {
            throw new IntegrityException('BagIt checksum mismatch: '.$path);
        }
    }

    private function checkOxum(string $file, int $bytes, int $count): void
    {
        $found = false;
        $label = null;
        $value = '';
        foreach (Manifest::lines($file) as $line) {
            if (1 !== preg_match('//u', $line)) {
                throw new IntegrityException('Non-UTF-8 bag-info.txt.');
            }
            if (preg_match('/^[ \t]+(.*)$/D', $line, $match) && null !== $label) {
                $value .= $match[1];
                continue;
            }
            $found = $this->checkOxumField($label, $value, $found, $bytes, $count);
            if (!preg_match('/^([^:\r\n]+?)[ \t]*:[ \t]+(.*)$/D', $line, $match) || trim($match[1]) !== $match[1]) {
                throw new IntegrityException('Malformed bag-info.txt field.');
            }
            $label = $match[1];
            $value = $match[2];
        }
        $this->checkOxumField($label, $value, $found, $bytes, $count);
    }

    private function checkOxumField(?string $label, string $value, bool $found, int $bytes, int $count): bool
    {
        if (null !== $label && 0 === strcasecmp($label, 'Payload-Oxum')) {
            if ($found || $value !== $bytes.'.'.$count) {
                throw new IntegrityException('Invalid or mismatched Payload-Oxum.');
            }

            return true;
        }

        return $found;
    }
}
