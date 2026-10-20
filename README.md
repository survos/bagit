# survos/bagit

A plain PHP 8.5+ library for BagIt directories. No Symfony container, bundle, provider
parser, command, S3 client, workflow or payload-extension policy is required.

The implementation follows [RFC 8493](https://www.rfc-editor.org/rfc/rfc8493.html)
within the explicit compatibility limits below. It generalizes the manifest path,
duplicate-entry and checksum checks in Harvest's `PennStateBag`, adds streaming
file I/O and full-directory coverage, and uses the staging/locking approach already
used by Harvest's Penn conversion tooling. Provider and issue parsing remain there.

## Directory API

```php
use Survos\BagIt\BagIt;

$bags = new BagIt();

// Copies all regular files (including hidden files) below raw into bag/data/.
// The parent directory must exist; the destination must not exist.
$created = $bags->create('/capture/raw', '/archive/bag', [
    'Source-Organization' => 'Example archive',
    'External-Identifier' => 'batch-example',
]);
$verified = $bags->verify('/archive/bag');
$restored = $bags->restore('/archive/bag', '/cache/bag');
```

Methods return `Verification` with `directory` (canonical absolute path), `version`,
`payloadFiles`, `payloadBytes` and `payloadAlgorithms`. `create()` defaults to
SHA-256 for payload and tag manifests; its fourth argument can select `sha512`,
`sha1` or `md5`. Use MD5 only for legacy interoperability. Metadata accepts
`array<string, string|list<string>>`; insertion order and repeated values are
preserved. `Payload-Oxum` is generated automatically and cannot be supplied.

`IntegrityException` identifies invalid, incomplete, unsafe or unsupported bag
contents. Invalid API metadata raises `InvalidArgumentException`; I/O and
publication failures raise `RuntimeException` (underlying adapter exceptions are
preserved). Verification stops at the first error and does not return a partial
success. `Manifest::read($file, $algorithm)` is also available for integrations
that need the path-to-digest map, without claiming that the full bag was verified.

## Optional file source adapters

The core depends only on PHP and ext-hash. Install `symfony/finder` or
`league/flysystem` in the consuming application when using the corresponding adapter.
`FileSource` has two methods: `files(): iterable<string>` and
`readStream(string $path): mixed` (a readable binary resource at position zero).
The library closes every returned stream. Sources must provide a stable, complete
inventory of their chosen scope and reject links/special files.

### Symfony Finder: select payload files

```php
use Survos\BagIt\Source\FinderSource;
use Symfony\Component\Finder\Finder;

$finder = (new Finder())
    ->files()->in('/capture/raw')
    ->ignoreDotFiles(false)->ignoreVCS(false)
    ->name('/\.(jsonl\.gz|json)$/');

$bags->createFrom(new FinderSource($finder, '/capture/raw'), '/archive/raw-bag');
```

Finder is a selection API. Its filters are application policy; the generic bag
library accepts XML, JP2, JSONL, compressed files and any other bytes. The adapter
requires files inside one explicit root and rejects selected symlinks, directory
link traversal and duplicate paths. Finder defaults can omit hidden/VCS files;
set them intentionally. Do not use a filtered selection to claim completeness
of an existing bag. The normal `verify()` always enumerates the entire bag,
including hidden files and directories.

### Flysystem: read existing storage zones without coupling to S3

```php
use Survos\BagIt\Source\FlysystemSource;

// Harvest's existing StorageService exposes the League Filesystem via getZone().
$zone = $storageService->getZone('raw');
$bags->createFrom(new FlysystemSource($zone, 'datasets/example/raw'), '/archive/bag');

// Download a complete directory bag into private local staging, verify all
// manifests and payload coverage, then publish it locally.
$bags->restoreFrom(new FlysystemSource($zone, 'bags/example'), '/cache/example');
```

`FlysystemSource` accepts `FilesystemReader`, not an S3-specific client, and calls
`listContents($prefix, true)` and `readStream()`. It validates prefix boundaries
and every relative path. Configure local Flysystem adapters with
`LocalFilesystemAdapter::DISALLOW_LINKS` (the default); Flysystem's generic listing
interface cannot reveal links that a backend silently skips or follows. Storage
credentials, remote consistency, retries, upload/eviction policies and visibility
belong to the application. Keep the remote prefix immutable throughout restore.
Do not equate S3 ETags with BagIt checksums.

There is deliberately no generic remote publication method: Flysystem `move()`
does not guarantee atomic publication of a directory. An application may upload
a verified local bag with `writeStream()` under an immutable versioned prefix,
then publish its catalog pointer using that storage layer's own protocol.

### Symfony Filesystem

Symfony Filesystem was evaluated alongside Finder and Flysystem. It provides
useful local operations (`copy`, `mirror`, `dumpFile`, `rename`) but is not a
storage reader interface. Wrapping it as a `FileSource` would add no capability
beyond `LocalDirectorySource`. In particular, file-level atomic `dumpFile()` and
`rename()` do not replace whole-bag staging, validation, locking and publication.
The core keeps those operations small and local, without a required Symfony
dependency. A Symfony application can use its existing Filesystem service to
prepare parent directories and manage lifecycle outside this library.

## Guarantees and limits

- Reads BagIt 0.9, 0.97 and 1.0 with UTF-8 tag files; creates 1.0. Other versions
  and character encodings fail explicitly. Supports MD5, SHA-1, SHA-256 and SHA-512.
- Every payload manifest must list every payload file exactly once. Every checksum
  in every payload/tag manifest is checked; missing, altered or unlisted payloads
  fail. Tag manifests are optional; when present they must cover every payload
  manifest, cannot list payloads or other tag manifests, and all listed tag files
  must exist and match. Payload-Oxum, when present, must match counts and bytes.
- Manifest readers support LF, CRLF, CR, uppercase digests, legacy `*` checksum-tool
  markers and BagIt's `%25`, `%0A`, `%0D` path escapes. Paths with spaces and UTF-8
  are supported. Absolute, traversal, empty-component, NUL, backslash and colon
  paths are rejected as a conservative portable path policy. No URL decoding of
  arbitrary escapes is performed. Local trees reject symlinks and special files.
- Hashing and copying stream payload bytes. Memory is proportional to the number
  and length of filenames/manifest entries, plus the longest metadata line, not
  payload file sizes. File inventories and manifest entries are held in memory.
- Identical payload bytes, paths, metadata order and algorithm produce identical
  manifest/tag bytes. No timestamps are inserted. Compressed inputs are copied
  unchanged. Filesystem timestamps and permissions are not preservation metadata;
  empty nested directories are not copied (empty `data/` is supported).
- Publication uses a private sibling staging directory, verification, and one
  same-filesystem rename. POSIX directory `flock` serializes this library's writers
  sharing a parent. Existing destinations are refused and failed staging is
  removed. This is atomic visibility, not power-loss durability: no fsync journal
  is provided. Uncooperative external writers and actively mutating input trees
  are outside the guarantee; keep sources immutable and destinations controlled.
- `fetch.txt` never triggers network access. Its files must already be present
  and checksummed, and declared lengths must match. Remote-hole bags must be
  materialized by the application before verification can succeed.
- BagIt checksums detect corruption; they do not authenticate a publisher. Supply
  trusted manifests or an external signature/authenticated catalog as needed.

## Packaging and transport

BagIt is the directory format; tar is an optional transport. There is no `package`
or archive-extraction API in this version. Existing Harvest archive handling and
mono storage facilities did not expose a reusable archive contract worth coupling
to the core. An application can verify a bag, archive it without recompressing
already-compressed payloads (for example, uncompressed tar), and use a hardened
extractor into private staging before calling `restore()` to verify and publish.
Never extract untrusted archives directly into the final destination. Archive path,
link, resource-limit and format policies remain the transport layer's responsibility.

## Development

From this directory after `composer install`:

```sh
composer test
composer analyse
```

Using mono's existing dependencies, without changing root autoload configuration:

```sh
../../vendor/bin/phpunit -c phpunit.xml.dist
../../vendor/bin/php-cs-fixer fix --config=../../.php-cs-fixer.dist.php --path-mode=intersection .
```

Tests cover legacy Penn-style MD5 fixtures, deterministic SHA manifests, arbitrary
and compressed payloads, missing/tampered/unlisted files, malformed/duplicate and
unsafe paths, symlinks, failed publication cleanup, concurrent writers, restore, adapter integration,
and a 32 MiB payload created/verified in a PHP subprocess limited to 16 MiB.
