<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\Exception\DownloadFailedException;
use Provisioner\OsmDataDownloader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
    public function downloadAlwaysWritesThroughTmpThenAtomicRenamesToFinalPath(): void
    {
        $downloader = new OsmDataDownloader($this->regionsDir, self::geofabrik(static fn (): MockResponse => new MockResponse('osm-bytes')));

        $downloader->download('bretagne');

        $finalPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        self::assertFileExists($finalPath);
        self::assertSame('osm-bytes', file_get_contents($finalPath));
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }

    #[Test]
    public function downloadCreatesRegionsDirectoryWhenMissing(): void
    {
        $downloader = new OsmDataDownloader($this->regionsDir, self::geofabrik(static fn (): MockResponse => new MockResponse('osm-bytes')));

        self::assertDirectoryDoesNotExist($this->regionsDir);

        $downloader->download('alsace');

        self::assertDirectoryExists($this->regionsDir);
    }

    #[Test]
    public function downloadOverwritesExistingFileAtomicallyViaTmp(): void
    {
        mkdir($this->regionsDir, 0o755, true);
        $finalPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        file_put_contents($finalPath, 'stale-content');

        $tmpPath = $finalPath.'.tmp';
        $testCase = $this;
        $tmpExistedDuringStreaming = false;

        $httpClient = self::geofabrik(static function () use ($tmpPath, &$tmpExistedDuringStreaming, $testCase): MockResponse {
            // While the response is being streamed, the .tmp file must exist
            // and the final path must still hold the stale content.
            $tmpExistedDuringStreaming = file_exists($tmpPath);
            $testCase::assertSame('stale-content', file_get_contents(\dirname($tmpPath).'/bretagne-latest.osm.pbf'));

            return new MockResponse('fresh-bytes');
        }, 'fresh-bytes');

        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);
        $downloader->download('bretagne');

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

        $httpClient = self::geofabrik(static fn (): MockResponse => new MockResponse('not found', ['http_code' => 404]));
        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);

        try {
            $downloader->download('bretagne');
            self::fail('Expected DownloadFailedException');
        } catch (DownloadFailedException $downloadFailedException) {
            self::assertStringContainsString('bretagne', $downloadFailedException->getMessage());
            self::assertStringContainsString('404', $downloadFailedException->getMessage());
        }

        // Stale final file must remain untouched, temp must be gone.
        self::assertFileExists($finalPath);
        self::assertSame('stale-but-valid', file_get_contents($finalPath));
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }

    #[Test]
    public function downloadTransportErrorRaisesDownloadFailedExceptionAndCleansTempFile(): void
    {
        mkdir($this->regionsDir, 0o755, true);
        $finalPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        file_put_contents($finalPath, 'stale-but-valid');

        $httpClient = self::geofabrik(static fn (): MockResponse => new MockResponse('', [
            'error' => 'connection refused',
        ]));
        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);

        try {
            $downloader->download('bretagne');
            self::fail('Expected DownloadFailedException');
        } catch (DownloadFailedException) {
        }

        self::assertFileExists($finalPath);
        self::assertSame('stale-but-valid', file_get_contents($finalPath));
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }

    #[Test]
    public function aTransferThatDoesNotMatchThePublishedChecksumIsRejected(): void
    {
        mkdir($this->regionsDir, 0o755, true);
        $finalPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        file_put_contents($finalPath, 'stale-but-valid');

        $downloader = new OsmDataDownloader($this->regionsDir, self::geofabrik(static fn (): MockResponse => new MockResponse('truncated'), 'the-whole-extract'));

        try {
            $downloader->download('bretagne');
            self::fail('Expected DownloadFailedException');
        } catch (DownloadFailedException $downloadFailedException) {
            self::assertStringContainsString('MD5', $downloadFailedException->getMessage());
        }

        self::assertSame('stale-but-valid', file_get_contents($finalPath));
        self::assertFileDoesNotExist($finalPath.'.tmp');
    }

    #[Test]
    public function aMissingChecksumFailsBeforeTheExtractIsFetched(): void
    {
        $requested = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return str_ends_with($url, '.md5') ? new MockResponse('', ['http_code' => 404]) : new MockResponse('osm-bytes');
        });
        $downloader = new OsmDataDownloader($this->regionsDir, $httpClient);

        try {
            $downloader->download('bretagne');
            self::fail('Expected DownloadFailedException');
        } catch (DownloadFailedException $downloadFailedException) {
            self::assertStringContainsString('Checksum', $downloadFailedException->getMessage());
        }

        self::assertCount(1, $requested);
        self::assertFileDoesNotExist($this->regionsDir.'/bretagne-latest.osm.pbf');
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

    /**
     * Serves the extract through $extract and, next to it, the `.md5` of $published
     * (by default the bytes the test's extract answers with).
     *
     * @param callable(): MockResponse $extract
     */
    public static function geofabrik(callable $extract, string $published = 'osm-bytes'): MockHttpClient
    {
        return new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '.md5')
            ? new MockResponse(md5($published).'  extract-latest.osm.pbf'."\n")
            : $extract());
    }
}
