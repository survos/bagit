<?php

declare(strict_types=1);

namespace Survos\BagIt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\BagIt\BagIt;
use Survos\BagIt\IntegrityException;
use Survos\BagIt\Manifest;

final class BagItTest extends TestCase
{
    private string $root;
    private BagIt $bags;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/bagit-test-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/source/nested', 0700, true);
        file_put_contents($this->root.'/source/nested/page.xml', '<xml/>');
        file_put_contents($this->root.'/source/image.jp2', "\x00\x01JP2");
        file_put_contents($this->root.'/source/records.jsonl.gz', gzencode("{}\n"));
        file_put_contents($this->root.'/source/info.json', '{}');
        $this->bags = new BagIt();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testCreateVerifyRestoreAndReproducibility(): void
    {
        $metadata = ['Source-Organization' => ['One', 'Two']];
        $first = $this->bags->create($this->root.'/source', $this->root.'/one', $metadata);
        $this->bags->create($this->root.'/source', $this->root.'/two', $metadata);
        self::assertSame(4, $first->payloadFiles);
        self::assertSame(['sha256'], $first->payloadAlgorithms);
        foreach (['bagit.txt', 'bag-info.txt', 'manifest-sha256.txt', 'tagmanifest-sha256.txt', 'data/records.jsonl.gz'] as $path) {
            self::assertFileEquals($this->root.'/one/'.$path, $this->root.'/two/'.$path);
        }
        $restored = $this->bags->restore($this->root.'/one', $this->root.'/restored');
        self::assertSame($first->payloadBytes, $restored->payloadBytes);
        self::assertSame(realpath($this->root.'/restored'), $restored->directory);
        self::assertSame($first->payloadFiles, $this->bags->verify($restored->directory)->payloadFiles);
    }

    public function testPennStyleMd5BagWithLegacyBinaryMarkerAndCrLf(): void
    {
        mkdir($this->root.'/penn/data', 0700, true);
        $root = $this->root.'/penn';
        file_put_contents($root.'/data/a.xml', '<xml/>');
        file_put_contents($root.'/data/a.jp2', 'JP2');
        file_put_contents($root.'/bagit.txt', "BagIt-Version: 0.97\r\nTag-File-Character-Encoding: UTF-8\r\n");
        file_put_contents($root.'/manifest-md5.txt', strtoupper(md5('<xml/>'))." *data/a.xml\r\n".md5('JP2')."  data/a.jp2\r\n");
        file_put_contents($root.'/tagmanifest-md5.txt', md5(file_get_contents($root.'/manifest-md5.txt'))."  manifest-md5.txt\r\n".md5(file_get_contents($root.'/bagit.txt'))."  bagit.txt\r\n");
        self::assertSame(['md5'], $this->bags->verify($root)->payloadAlgorithms);
        file_put_contents($root.'/bagit.txt', "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n");
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('checksum mismatch: bagit.txt');
        $this->bags->verify($root);
    }

    public static function corruptions(): iterable
    {
        yield 'tampered' => ['tampered', 'checksum mismatch'];
        yield 'missing' => ['missing', 'Missing manifest-listed'];
        yield 'unlisted' => ['unlisted', 'Unlisted payload'];
        yield 'symlink' => ['symlink', 'Symlink'];
        yield 'directory symlink' => ['directory-link', 'Symlink'];
        yield 'missing data' => ['data', 'Missing bagit.txt or data'];
        yield 'unsupported algorithm' => ['algorithm', 'Unsupported checksum'];
        yield 'incomplete second manifest' => ['second', 'Unlisted payload'];
        yield 'tag omission' => ['tag-omission', 'omits a payload manifest'];
        yield 'tag references payload' => ['tag-payload', 'Forbidden tag manifest'];
        yield 'wrong oxum' => ['oxum', 'Payload-Oxum'];
    }

    #[DataProvider('corruptions')]
    public function testRejectsCorruption(string $kind, string $message): void
    {
        $root = $this->root.'/bag';
        $this->bags->create($this->root.'/source', $root);
        if ('tampered' === $kind) {
            file_put_contents($root.'/data/info.json', 'changed');
        } elseif ('missing' === $kind) {
            unlink($root.'/data/info.json');
        } elseif ('unlisted' === $kind) {
            file_put_contents($root.'/data/extra', 'x');
        } elseif ('symlink' === $kind) {
            symlink($this->root.'/source/info.json', $root.'/extra');
        } elseif ('directory-link' === $kind) {
            symlink($this->root.'/source', $root.'/linked');
        } elseif ('data' === $kind) {
            rename($root.'/data', $root.'/elsewhere');
        } elseif ('algorithm' === $kind) {
            file_put_contents($root.'/manifest-unknown.txt', '');
        } elseif ('second' === $kind) {
            unlink($root.'/tagmanifest-sha256.txt');
            file_put_contents($root.'/manifest-md5.txt', '');
        } elseif ('tag-omission' === $kind) {
            file_put_contents($root.'/tagmanifest-sha256.txt', '');
        } elseif ('tag-payload' === $kind) {
            file_put_contents($root.'/tagmanifest-sha256.txt', hash('sha256', '{}')."  data/info.json\n");
        } elseif ('oxum' === $kind) {
            unlink($root.'/tagmanifest-sha256.txt');
            file_put_contents($root.'/bag-info.txt', "Payload-Oxum: 0.0\n");
        }
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage($message);
        $this->bags->verify($root);
    }

    public static function unsafePaths(): iterable
    {
        foreach (['../outside', '/absolute', 'data/../outside', 'data/./x', 'data//x', 'C:/file', 'data\\x', "data/\0x", 'data/%2foutside', 'data/%2E%2E/x'] as $path) {
            yield bin2hex($path) => [$path];
        }
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafeManifestPaths(string $path): void
    {
        $file = $this->root.'/manifest';
        file_put_contents($file, md5('x').'  '.$path."\n");
        $this->expectException(IntegrityException::class);
        Manifest::read($file, 'md5');
    }

    public function testDuplicatePathsAreRejected(): void
    {
        $file = $this->root.'/manifest';
        file_put_contents($file, md5('x')."  data/x\n".md5('x')." data/x\n");
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('Duplicate');
        Manifest::read($file, 'md5');
    }

    public function testEscapedNamesAndEmptyPayload(): void
    {
        file_put_contents($this->root."/source/percent%0A\nline\r.txt", 'x');
        $result = $this->bags->create($this->root.'/source', $this->root.'/escaped');
        self::assertSame(5, $result->payloadFiles);
        mkdir($this->root.'/empty');
        self::assertSame(0, $this->bags->create($this->root.'/empty', $this->root.'/empty-bag', [], 'sha512')->payloadFiles);
    }

    public function testFailedBuildCleansStagingAndDoesNotPublish(): void
    {
        try {
            $this->bags->create($this->root.'/source', $this->root.'/bad', ['Invalid: Label' => 'x']);
            self::fail('Expected invalid metadata.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($this->root.'/bad');
            self::assertSame([], glob($this->root.'/.bagit-*'));
        }
    }

    public function testNeverOverwritesExistingDestination(): void
    {
        mkdir($this->root.'/exists');
        file_put_contents($this->root.'/exists/keep', 'keep');
        try {
            $this->bags->create($this->root.'/source', $this->root.'/exists');
            self::fail('Expected destination refusal.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
            self::assertSame('keep', file_get_contents($this->root.'/exists/keep'));
        }
    }

    public function testCrOnlyManifestsAndMissingFinalNewline(): void
    {
        $file = $this->root.'/manifest';
        file_put_contents($file, md5('a')." data/a\r".md5('b').' data/b');
        self::assertSame(['data/a' => md5('a'), 'data/b' => md5('b')], Manifest::read($file, 'md5'));
    }

    public function testSourceSymlinkRejected(): void
    {
        symlink($this->root.'/source/info.json', $this->root.'/source/link');
        $this->expectException(IntegrityException::class);
        $this->bags->create($this->root.'/source', $this->root.'/bag');
    }

    public function testNestedDestinationRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bags->create($this->root.'/source', $this->root.'/source/bag');
    }

    public function testMalformedDigestRejected(): void
    {
        file_put_contents($this->root.'/manifest', "abcd data/x\n");
        $this->expectException(IntegrityException::class);
        Manifest::read($this->root.'/manifest', 'md5');
    }

    public function testStreamingPayloadLargerThanPhpMemoryLimit(): void
    {
        $stream = fopen($this->root.'/source/large', 'wb');
        $chunk = str_repeat('a', 1024 * 1024);
        for ($i = 0; $i < 32; ++$i) {
            fwrite($stream, $chunk);
        }
        fclose($stream);
        $script = $this->root.'/verify.php';
        file_put_contents($script, '<?php define("BAGIT_CORE_ONLY", true); require '.var_export(__DIR__.'/bootstrap.php', true).'; $b = new \Survos\BagIt\BagIt(); $r = $b->create($argv[1], $argv[2]); echo $b->verify($r->directory)->payloadFiles;');
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', $script, $this->root.'/source', $this->root.'/large-bag'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('5', $output);
    }

    public function testRestoreRefusesCorruptedBagBeforePublishing(): void
    {
        $this->bags->create($this->root.'/source', $this->root.'/bag');
        file_put_contents($this->root.'/bag/data/info.json', 'bad');
        try {
            $this->bags->restore($this->root.'/bag', $this->root.'/restored');
            self::fail('Expected integrity error.');
        } catch (IntegrityException) {
            self::assertDirectoryDoesNotExist($this->root.'/restored');
            self::assertSame([], glob($this->root.'/.bagit-*'));
        }
    }

    public function testFinderSelectsPayloadIncludingExplicitHiddenFiles(): void
    {
        file_put_contents($this->root.'/source/.hidden.json', '{}');
        $finder = (new \Symfony\Component\Finder\Finder())->files()->in($this->root.'/source')->name('/\.json$/')->ignoreDotFiles(false);
        $source = new \Survos\BagIt\Source\FinderSource($finder, $this->root.'/source');
        $report = $this->bags->createFrom($source, $this->root.'/selected');
        self::assertSame(2, $report->payloadFiles);
        self::assertFileExists($this->root.'/selected/data/.hidden.json');
        self::assertFileDoesNotExist($this->root.'/selected/data/image.jp2');
    }

    public function testFinderRejectsOutsideRoot(): void
    {
        file_put_contents($this->root.'/outside', 'x');
        $finder = (new \Symfony\Component\Finder\Finder())->files()->in($this->root)->name('outside');
        $this->expectException(IntegrityException::class);
        new \Survos\BagIt\Source\FinderSource($finder, $this->root.'/source');
    }

    public function testFinderRejectsFollowedDirectoryLinks(): void
    {
        symlink($this->root.'/source/nested', $this->root.'/source/linked');
        $finder = (new \Symfony\Component\Finder\Finder())->files()->in($this->root.'/source/linked')->followLinks();
        $this->expectException(IntegrityException::class);
        new \Survos\BagIt\Source\FinderSource($finder, $this->root.'/source');
    }

    public function testFlysystemCreateAndRestoreWithPrefix(): void
    {
        $filesystem = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($this->root));
        $source = new \Survos\BagIt\Source\FlysystemSource($filesystem, 'source');
        self::assertSame(4, $this->bags->createFrom($source, $this->root.'/remote-bag')->payloadFiles);
        $bag = new \Survos\BagIt\Source\FlysystemSource($filesystem, 'remote-bag');
        self::assertSame(4, $this->bags->restoreFrom($bag, $this->root.'/downloaded')->payloadFiles);
        self::assertFileEquals($this->root.'/remote-bag/manifest-sha256.txt', $this->root.'/downloaded/manifest-sha256.txt');
    }

    public function testFlysystemRejectsTraversalPrefix(): void
    {
        $filesystem = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($this->root));
        $this->expectException(IntegrityException::class);
        new \Survos\BagIt\Source\FlysystemSource($filesystem, '../source');
    }

    public function testFlysystemLocalAdapterRejectsSymlinks(): void
    {
        symlink($this->root.'/source/info.json', $this->root.'/source/link');
        $filesystem = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($this->root));
        $this->expectException(\League\Flysystem\UnableToListContents::class);
        $this->bags->createFrom(new \Survos\BagIt\Source\FlysystemSource($filesystem, 'source'), $this->root.'/bag');
    }

    public function testConcurrentWritersPublishExactlyOneCompleteBag(): void
    {
        $script = $this->root.'/writer.php';
        file_put_contents($script, '<?php define("BAGIT_CORE_ONLY", true); require '.var_export(__DIR__.'/bootstrap.php', true).'; try { (new \Survos\BagIt\BagIt())->create($argv[1], $argv[2]); } catch (\RuntimeException $e) { fwrite(STDERR, $e->getMessage()); exit(2); }');
        $processes = [];
        $streams = [];
        for ($i = 0; $i < 2; ++$i) {
            $processes[] = proc_open([PHP_BINARY, $script, $this->root.'/source', $this->root.'/race'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $streams[] = $pipes;
        }
        $codes = [];
        $errors = '';
        foreach ($processes as $i => $process) {
            stream_get_contents($streams[$i][1]);
            $errors .= stream_get_contents($streams[$i][2]);
            fclose($streams[$i][1]);
            fclose($streams[$i][2]);
            $codes[] = proc_close($process);
        }
        sort($codes);
        self::assertSame([0, 2], $codes, $errors);
        self::assertStringContainsString('already exists', $errors);
        self::assertSame(4, $this->bags->verify($this->root.'/race')->payloadFiles);
        self::assertSame([], glob($this->root.'/.bagit-*'));
    }

    public function testFailedSourceReadCleansStaging(): void
    {
        $source = new class implements \Survos\BagIt\Source\FileSource {
            public function files(): iterable
            {
                return ['file'];
            }

            public function readStream(string $path): mixed
            {
                throw new \RuntimeException('backend unavailable');
            }
        };
        try {
            $this->bags->createFrom($source, $this->root.'/failed');
            self::fail('Expected backend error.');
        } catch (\RuntimeException $e) {
            self::assertSame('backend unavailable', $e->getMessage());
            self::assertDirectoryDoesNotExist($this->root.'/failed');
            self::assertSame([], glob($this->root.'/.bagit-*'));
        }
    }

    public function testFetchFileRequiresMaterializedPayloadAndCorrectLength(): void
    {
        $this->bags->create($this->root.'/source', $this->root.'/bag');
        file_put_contents($this->root.'/bag/fetch.txt', "https://example.test/info.json 2 data/info.json\n");
        self::assertSame(4, $this->bags->verify($this->root.'/bag')->payloadFiles);
        file_put_contents($this->root.'/bag/fetch.txt', "https://example.test/info.json 3 data/info.json\n");
        $this->expectException(IntegrityException::class);
        $this->bags->verify($this->root.'/bag');
    }
}
