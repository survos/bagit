<?php

declare(strict_types=1);

namespace Survos\BagIt;

use Survos\BagIt\Internal\CheckedManifest;
use Survos\BagIt\Internal\Inspector;
use Survos\BagIt\Source\FileSource;
use Survos\BagIt\Source\LocalDirectorySource;
use whikloj\BagItTools\Bag;

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
            $engine = Bag::create($stage);
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
            $info = '';
            foreach ($metadata as $label => $values) {
                if (!is_string($label) || '' === $label || trim($label) !== $label || preg_match('/[:\r\n\x00]/', $label) || 1 !== preg_match('//u', $label) || in_array(strtolower($label), ['payload-oxum', 'bagit-profile-identifier'], true)) {
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
            (new CheckedManifest($engine, $algorithm, $manifest, array_fill_keys($paths, '')))->update();
            $tags = ['bag-info.txt', 'bagit.txt', $manifest];
            sort($tags, SORT_STRING);
            (new CheckedManifest($engine, $algorithm, 'tagmanifest-'.$algorithm.'.txt', array_fill_keys($tags, '')))->update();

            return $this->verify($stage);
        });
    }

    /** Verify offline, throwing the first diagnostic on failure. */
    public function verify(string $directory): Verification
    {
        $inspection = $this->inspect($directory);
        if (null === $inspection->verification) {
            throw new IntegrityException($inspection->errors[0]->message);
        }

        return $inspection->verification;
    }

    /** Collect diagnostics without downloading fetch entries or changing bag files. */
    public function inspect(string $directory): Inspection
    {
        return (new Inspector())->inspect($directory);
    }

    /** @return list<MetadataEntry> Ordered fields, preserving repeated labels and their casing. */
    public function readMetadata(string $directory): array
    {
        return (new Inspector())->metadata($directory);
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
            if (!mkdir($stage.'/data', 0700, true)) {
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
}
