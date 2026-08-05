<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\DownloadFailedException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches one zone's Geofabrik extract into the provisioner's regions directory.
 *
 * It used to also merge the downloaded extracts with `osmium merge`, back when a run
 * re-imported the whole cumulative selection. One zone per run (ADR-049 §1) means one
 * extract to tags-filter, so there is nothing left to merge and no subprocess left to
 * run: this class now only speaks HTTP.
 */
final readonly class OsmDataDownloader
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private string $regionsDir,
        ?HttpClientInterface $httpClient = null,
        float $idleTimeoutSeconds = 120.0,
        float $maxDurationSeconds = 7200.0,
    ) {
        // Multi-GB PBF downloads must not hang forever (ADR-041): cap both the
        // per-chunk idle wait and the total transfer so a stalled Geofabrik
        // mirror fails fast instead of blocking the whole provisioning run.
        $this->httpClient = $httpClient ?? HttpClient::create([
            'max_redirects' => 2,
            'timeout' => $idleTimeoutSeconds,
            'max_duration' => $maxDurationSeconds,
        ]);
    }

    public function targetPath(string $slug): string
    {
        return \sprintf('%s/%s-latest.osm.pbf', $this->regionsDir, $slug);
    }

    /**
     * Download the PBF for the given slug.
     *
     * The file is always written to "{target}.tmp" first then atomically
     * renamed to the final path so a partial download (or any pre-rename
     * failure) cannot corrupt an existing PBF at the target path.
     *
     * @throws DownloadFailedException
     */
    public function download(string $slug): void
    {
        if (!is_dir($this->regionsDir) && !mkdir($this->regionsDir, 0o755, true) && !is_dir($this->regionsDir)) {
            throw new DownloadFailedException(\sprintf('Cannot create regions directory "%s"', $this->regionsDir));
        }

        $targetPath = $this->targetPath($slug);
        // Always write through a .tmp + atomic rename so a transport failure
        // can never corrupt an existing PBF at $targetPath. The caller controls
        // whether to skip already-present files via file_exists() before calling download().
        $writePath = $targetPath.'.tmp';
        $url = GeofabrikRegionRegistry::downloadUrl($slug);

        $fileHandle = fopen($writePath, 'w');
        if (false === $fileHandle) {
            throw new DownloadFailedException(\sprintf('Cannot open "%s" for writing', $writePath));
        }

        try {
            $response = $this->httpClient->request('GET', $url);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new DownloadFailedException(\sprintf('Download of "%s" failed with HTTP %d', $slug, $statusCode));
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                if (false === fwrite($fileHandle, $chunk->getContent())) {
                    throw new DownloadFailedException(\sprintf('Failed to write to "%s" while downloading "%s"', $writePath, $slug));
                }
            }
        } catch (HttpClientExceptionInterface $e) {
            fclose($fileHandle);
            @unlink($writePath);

            throw new DownloadFailedException(\sprintf('Download of "%s" failed: %s', $slug, $e->getMessage()), 0, $e);
        } catch (DownloadFailedException $e) {
            fclose($fileHandle);
            @unlink($writePath);

            throw $e;
        }

        fclose($fileHandle);

        if (!rename($writePath, $targetPath)) {
            @unlink($writePath);

            throw new DownloadFailedException(\sprintf('Atomic rename of "%s" to "%s" failed', $writePath, $targetPath));
        }
    }
}
