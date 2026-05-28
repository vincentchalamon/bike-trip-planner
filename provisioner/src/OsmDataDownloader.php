<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\DownloadFailedException;
use Provisioner\Exception\MergeFailedException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OsmDataDownloader
{
    private HttpClientInterface $httpClient;

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory factory used to build the osmium process; defaults to a real {@see Process}
     */
    public function __construct(
        private string $regionsDir,
        ?HttpClientInterface $httpClient = null,
        ?\Closure $processFactory = null,
        private int $mergeTimeoutSeconds = 600,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    public function targetPath(string $slug): string
    {
        return \sprintf('%s/%s-latest.osm.pbf', $this->regionsDir, $slug);
    }

    /**
     * Download the PBF for the given slug.
     *
     * When $forceOverwrite is true, the file is written to "{target}.tmp" first
     * then atomically renamed to the final path so a partial download cannot
     * corrupt an existing PBF if the process is interrupted.
     *
     * @throws DownloadFailedException
     */
    public function download(string $slug, bool $forceOverwrite): void
    {
        if (!is_dir($this->regionsDir) && !mkdir($this->regionsDir, 0o755, true) && !is_dir($this->regionsDir)) {
            throw new DownloadFailedException(\sprintf('Cannot create regions directory "%s"', $this->regionsDir));
        }

        $targetPath = $this->targetPath($slug);
        $writePath = $forceOverwrite ? $targetPath.'.tmp' : $targetPath;
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

        if ($forceOverwrite && !rename($writePath, $targetPath)) {
            @unlink($writePath);

            throw new DownloadFailedException(\sprintf('Atomic rename of "%s" to "%s" failed', $writePath, $targetPath));
        }
    }

    /**
     * Merge the given PBF files into $outputPath via `osmium merge --overwrite`.
     *
     * @param list<string> $pbfPaths
     *
     * @throws MergeFailedException
     */
    public function merge(array $pbfPaths, string $outputPath): void
    {
        if ([] === $pbfPaths) {
            throw new MergeFailedException('Cannot run osmium merge with an empty list of PBF files');
        }

        $command = array_merge(
            ['osmium', 'merge', '--overwrite', '-o', $outputPath],
            $pbfPaths,
        );

        $process = ($this->processFactory)($command);
        $process->setTimeout((float) $this->mergeTimeoutSeconds);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new MergeFailedException(\sprintf(
                'osmium merge failed (exit %s): %s',
                (string) $process->getExitCode(),
                $process->getErrorOutput(),
            ));
        }
    }
}
