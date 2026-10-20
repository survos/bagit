<?php

declare(strict_types=1);

namespace Survos\BagIt\Internal;

use Survos\BagIt\Diagnostic;
use Survos\BagIt\Filesystem;
use Survos\BagIt\Inspection;
use Survos\BagIt\IntegrityException;
use Survos\BagIt\Manifest;
use Survos\BagIt\MetadataEntry;
use Survos\BagIt\Verification;
use whikloj\BagItTools\Bag;
use whikloj\BagItTools\Exceptions\BagItException;

/** @internal Offline safety/compatibility boundary around BagItTools. */
final class Inspector
{
    public static function version(string $root): string
    {
        if (!is_file($root.'/bagit.txt') || !is_dir($root.'/data')) {
            throw new IntegrityException('Missing bagit.txt or data directory.');
        }
        $lines = iterator_to_array(Manifest::lines($root.'/bagit.txt'));
        if (2 !== count($lines) || !preg_match('/^BagIt-Version: (0\.9|0\.96|0\.97|1\.0)$/D', $lines[0], $match)
            || 0 !== strcasecmp($lines[1], 'Tag-File-Character-Encoding: UTF-8')) {
            throw new IntegrityException('Unsupported or malformed BagIt declaration (requires 0.9, 0.96, 0.97 or 1.0 and UTF-8).');
        }

        return $match[1];
    }

    /** Bag::load resolves profile URLs; reject them before calling upstream. */
    public static function requireOffline(string $root): void
    {
        if (is_file($root.'/bag-info.txt')) {
            foreach (Manifest::lines($root.'/bag-info.txt') as $line) {
                if (preg_match('/^\s*BagIt-Profile-Identifier\s*:/iu', $line)) {
                    throw new IntegrityException('BagIt profiles require network access and are unsupported by offline verification.');
                }
            }
        }
    }

    /** @return list<MetadataEntry> Reading metadata does not claim checksum validity. */
    public function metadata(string $directory): array
    {
        $root = Filesystem::root($directory);
        Filesystem::files($root);
        self::version($root);
        self::requireOffline($root);
        $bag = Bag::load($root);
        foreach ($bag->getErrors() as $error) {
            if ('bag-info.txt' === $error['file']) {
                throw new IntegrityException($error['message']);
            }
        }

        return array_map(static fn (array $row): MetadataEntry => new MetadataEntry($row['tag'], $row['value']), $bag->getBagInfoData());
    }

    public function inspect(string $directory): Inspection
    {
        $errors = [];
        $warnings = [];
        try {
            $root = Filesystem::root($directory);
            $files = array_fill_keys(Filesystem::files($root), true);
            $version = self::version($root);
            self::requireOffline($root);
        } catch (IntegrityException $e) {
            return new Inspection([new Diagnostic('unsafe_or_unsupported_bag', $e->getMessage(), $directory)], null);
        }
        $payload = array_filter($files, static fn (string|int $p): bool => str_starts_with((string) $p, 'data/'), ARRAY_FILTER_USE_KEY);
        $manifests = [];
        $entries = [];
        $unsafe = false;
        foreach ($files as $path => $_) {
            $path = (string) $path;
            if (!preg_match('/^(tag)?manifest-([^\/]+)\.txt$/D', $path, $match)) {
                continue;
            }
            $manifests[$path] = ['algorithm' => $match[2], 'tag' => '' !== $match[1]];
            try {
                $entries[$path] = Manifest::read($root.'/'.$path, $match[2], $version);
            } catch (IntegrityException $e) {
                $errors[] = new Diagnostic('invalid_manifest', $e->getMessage(), $path, $path);
                $unsafe = true;
            }
        }
        $payloadManifests = array_filter($manifests, static fn (array $m): bool => !$m['tag']);
        if ([] === $payloadManifests) {
            $errors[] = new Diagnostic('missing_manifest', 'Missing payload manifest.');
        }
        $covered = [];
        foreach ($entries as $manifest => $hashes) {
            $tag = $manifests[$manifest]['tag'];
            foreach ($hashes as $path => $_) {
                $path = (string) $path;
                if (($tag && (str_starts_with($path, 'data/') || ($manifests[$path]['tag'] ?? false)))
                    || (!$tag && !str_starts_with($path, 'data/'))) {
                    $errors[] = new Diagnostic('invalid_reference', $tag ? 'Forbidden tag manifest reference: '.$path : 'Manifest references a non-payload path: '.$path, $path, $manifest);
                    $unsafe = true;
                }
                if (!isset($files[$path])) {
                    $errors[] = new Diagnostic('missing_file', 'Missing manifest-listed file: '.$path, $path, $manifest);
                    // A directory must never be passed to upstream's hash_file().
                    if (is_dir($root.'/'.$path)) {
                        $unsafe = true;
                    }
                }
            }
            if ($tag && '0.96' !== $version) {
                foreach (array_diff_key($payloadManifests, $hashes) as $path => $_) {
                    $errors[] = new Diagnostic('missing_tag_coverage', 'Tag manifest omits a payload manifest: '.$path, $path, $manifest);
                }
            } elseif (!$tag) {
                $covered += $hashes;
                if ('0.96' !== $version) {
                    foreach (array_diff_key($payload, $hashes) as $path => $_) {
                        $errors[] = new Diagnostic('unlisted_payload', 'Unlisted payload in '.$manifest.': '.$path, $path, $manifest);
                    }
                }
            }
        }
        if ('0.96' === $version) {
            foreach (array_diff_key($payload, $covered) as $path => $_) {
                $errors[] = new Diagnostic('unlisted_payload', 'Unlisted payload: '.$path, $path);
            }
        }
        $fetchEntries = [];
        if (isset($files['fetch.txt'])) {
            foreach (Manifest::lines($root.'/fetch.txt') as $line) {
                try {
                    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:[^ \t]*[ \t]+(-|[0-9]+)[ \t]+(.+)$/Du', $line, $match)
                        || ('0.96' !== $version && preg_match('/%(?!25|0[aAdD])/', $match[2]))) {
                        throw new IntegrityException('Malformed fetch.txt entry.');
                    }
                    $path = '0.96' === $version ? $match[2] : str_ireplace(['%0A', '%0D', '%25'], ["\n", "\r", '%'], $match[2]);
                    Filesystem::path($path);
                    if (!str_starts_with($path, 'data/')) {
                        throw new IntegrityException('Fetch entry references a non-payload file: '.$path);
                    }
                    $fetchEntries[] = ['path' => $path, 'size' => $match[1]];
                } catch (IntegrityException $e) {
                    $errors[] = new Diagnostic('unsafe_fetch', $e->getMessage(), 'fetch.txt');
                    $unsafe = true;
                }
            }
        }
        // Fail closed before upstream parses/hashes any unsafe manifest.
        if ($unsafe) {
            return new Inspection($errors, null);
        }
        $bytes = 0;
        try {
            $bag = Bag::load($root);
            foreach ($bag->getErrors() as $error) {
                if ('0.96' === $version && 'fetch.txt' === $error['file']) {
                    continue; // Legacy literal paths were checked above, before upstream decoding.
                }
                $errors[] = new Diagnostic('bagittools_error', $error['message'], $error['file']);
            }
            foreach ($bag->getWarnings() as $warning) {
                $warnings[] = new Diagnostic('bagittools_warning', $warning['message'], $warning['file']);
            }
            // Do not call Bag::isValid(): it downloads fetch.txt entries and can write files.
            foreach ($manifests as $path => $definition) {
                $algorithm = $definition['algorithm'];
                $manifest = '0.96' === $version
                    ? new CheckedManifest($bag, $algorithm, $path, $entries[$path])
                    : ($definition['tag'] ? $bag->getTagManifests()[$algorithm] : $bag->getPayloadManifests()[$algorithm]);
                $manifest->validate();
                foreach ($manifest->getErrors() as $error) {
                    $errors[] = new Diagnostic('manifest_error', $error['message'], $error['file'], $path);
                }
                foreach ($manifest->getWarnings() as $warning) {
                    $warnings[] = new Diagnostic('manifest_warning', $warning['message'], $warning['file'], $path);
                }
            }
            foreach ($payload as $path => $_) {
                $size = filesize($root.'/'.$path);
                if (false === $size) {
                    throw new \RuntimeException('Cannot determine payload size: '.$path);
                }
                $bytes += $size;
            }
            $oxums = array_filter($bag->getBagInfoData(), static fn (array $row): bool => 0 === strcasecmp($row['tag'], 'Payload-Oxum'));
            if (count($oxums) > 1 || ([] !== $oxums && reset($oxums)['value'] !== $bytes.'.'.count($payload))) {
                $errors[] = new Diagnostic('invalid_oxum', 'Invalid or mismatched Payload-Oxum.', 'bag-info.txt');
            }
            // Fetch is parsed by upstream, but materialization is the application's job.
            foreach ($fetchEntries as $fetch) {
                $path = $fetch['path'];
                if (!isset($payload[$path]) || ('-' !== $fetch['size'] && (string) filesize($root.'/'.$path) !== $fetch['size'])) {
                    $errors[] = new Diagnostic('incomplete_fetch', 'Missing payload or incorrect length in fetch.txt: '.$path, $path, 'fetch.txt');
                }
            }
        } catch (BagItException $e) {
            $errors[] = new Diagnostic('bagittools_error', $e->getMessage());
        } catch (IntegrityException $e) {
            $errors[] = new Diagnostic('unsafe_fetch', $e->getMessage(), 'fetch.txt');
        }

        return new Inspection($errors, [] === $errors ? new Verification($root, $version, count($payload), $bytes, array_column($payloadManifests, 'algorithm')) : null, $warnings);
    }
}
