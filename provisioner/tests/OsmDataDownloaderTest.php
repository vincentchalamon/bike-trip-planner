<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\Exception\DownloadFailedException;
use Provisioner\Exception\MergeFailedException;
use Provisioner\OsmDataDownloader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class OsmDataDownloaderTest extends TestCase
{
    private string $tmpDir;

    private string $regionsDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/osm-downloader-'.uniqid('', true);
        $this->regionsDir = $this->tmpDir.'/regions';
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $this->removeDir($entry);
            } else {
                unlink($entry);
            }
        }
        rmdir($dir);
    }

    #[Test]
    public function downloadInstallModeWritesDirectlyToFinalPath(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('osm-bytes'));
        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);

        $downloader->download('bretagne', forceOverwrite: false);

        $finalPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        self::assertFileExists($finalPath);
        self::assertSame('osm-bytes', file_get_contents($finalPath));
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }

    #[Test]
    public function downloadCreatesRegionsDirectoryWhenMissing(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('osm-bytes'));
        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);

        self::assertDirectoryDoesNotExist($this->regionsDir);

        $downloader->download('alsace', forceOverwrite: false);

        self::assertDirectoryExists($this->regionsDir);
    }

    #[Test]
    public function downloadForceOverwriteWritesToTempThenAtomicRenames(): void
    {
        mkdir($this->regionsDir, 0o755, true);
        $finalPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        file_put_contents($finalPath, 'stale-content');

        $tmpPath = $finalPath.'.tmp';
        $testCase = $this;
        $tmpExistedDuringStreaming = false;

        $httpClient = new MockHttpClient(static function () use ($tmpPath, &$tmpExistedDuringStreaming, $testCase): MockResponse {
            // While the response is being streamed, the .tmp file must exist
            // and the final path must still hold the stale content.
            $tmpExistedDuringStreaming = file_exists($tmpPath);
            $testCase::assertSame('stale-content', file_get_contents(\dirname($tmpPath).'/bretagne-latest.osm.pbf'));

            return new MockResponse('fresh-bytes');
        });

        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);
        $downloader->download('bretagne', forceOverwrite: true);

        self::assertTrue($tmpExistedDuringStreaming, '.tmp file should be created before streaming starts');
        self::assertFileExists($finalPath);
        self::assertSame('fresh-bytes', file_get_contents($finalPath));
        self::assertFileDoesNotExist($tmpPath);
    }

    #[Test]
    public function downloadHttpErrorRaisesDownloadFailedExceptionAndCleansTempFile(): void
    {
        mkdir($this->regionsDir, 0o755, true);
        $finalPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        file_put_contents($finalPath, 'stale-but-valid');

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('not found', ['http_code' => 404]));
        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);

        try {
            $downloader->download('bretagne', forceOverwrite: true);
            self::fail('Expected DownloadFailedException');
        } catch (DownloadFailedException $e) {
            self::assertStringContainsString('bretagne', $e->getMessage());
            self::assertStringContainsString('404', $e->getMessage());
        }

        // Stale final file must remain untouched, temp must be gone.
        self::assertFileExists($finalPath);
        self::assertSame('stale-but-valid', file_get_contents($finalPath));
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }

    #[Test]
    public function downloadTransportErrorRaisesDownloadFailedException(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('', [
            'error' => 'connection refused',
        ]));
        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);

        $this->expectException(DownloadFailedException::class);

        $downloader->download('bretagne', forceOverwrite: false);
    }

    #[Test]
    public function mergeBuildsOsmiumCommandAndSucceeds(): void
    {
        /** @var list<string>|null $capturedCommand */
        $capturedCommand = null;
        $factory = function (array $command) use (&$capturedCommand): Process {
            $capturedCommand = $command;

            // Trivial process that always succeeds.
            return new Process(['true']);
        };

        $downloader = new OsmDataDownloader(
            regionsDir: $this->regionsDir,
            processFactory: $factory,
        );

        $output = $this->tmpDir.'/merged.osm.pbf';
        $downloader->merge(['/a.osm.pbf', '/b.osm.pbf'], $output);

        self::assertSame(
            ['osmium', 'merge', '--overwrite', '-o', $output, '/a.osm.pbf', '/b.osm.pbf'],
            $capturedCommand,
        );
    }

    #[Test]
    public function mergeFailureRaisesMergeFailedExceptionWithStderr(): void
    {
        $factory = static fn (array $command): Process => new Process([
            'sh', '-c', 'echo "boom" 1>&2; exit 2',
        ]);

        $downloader = new OsmDataDownloader(
            regionsDir: $this->regionsDir,
            processFactory: $factory,
        );

        try {
            $downloader->merge(['/a.osm.pbf'], $this->tmpDir.'/merged.osm.pbf');
            self::fail('Expected MergeFailedException');
        } catch (MergeFailedException $e) {
            self::assertStringContainsString('osmium merge failed', $e->getMessage());
            self::assertStringContainsString('boom', $e->getMessage());
            self::assertStringContainsString('exit 2', $e->getMessage());
        }
    }

    #[Test]
    public function mergeWithEmptyListThrows(): void
    {
        $downloader = new OsmDataDownloader($this->regionsDir);

        $this->expectException(MergeFailedException::class);

        $downloader->merge([], $this->tmpDir.'/merged.osm.pbf');
    }

    #[Test]
    public function targetPathReturnsCanonicalLayout(): void
    {
        $downloader = new OsmDataDownloader($this->regionsDir);

        self::assertSame(
            $this->regionsDir.'/bretagne-latest.osm.pbf',
            $downloader->targetPath('bretagne'),
        );
    }
}
