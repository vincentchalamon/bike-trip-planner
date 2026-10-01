<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Streams a national feed (DataTourisme flux, OpenAgenda export) to disk chunk by chunk,
 * so memory stays constant whatever the feed size.
 */
final readonly class FeedDownloader
{
    /**
     * @param string $label  what the feed is called in error messages ('DataTourisme flux')
     * @param string $secret credential the URL carries (app key, API key), redacted from a
     *                       transport error: it quotes the URL, and the message lands in
     *                       provisioner.log and on the console
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $url,
        private string $label,
        private string $secret = '',
    ) {
    }

    /**
     * @throws ImportFailedException
     */
    public function download(string $path): void
    {
        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new ImportFailedException(\sprintf('Cannot open "%s" for writing', $path));
        }

        try {
            $response = $this->httpClient->request('GET', $this->url);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new ImportFailedException(\sprintf('%s download failed with HTTP %d', $this->label, $status));
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                if (false === fwrite($handle, $chunk->getContent())) {
                    throw new ImportFailedException(\sprintf('Failed to write the %s to "%s"', $this->label, $path));
                }
            }
        } catch (HttpClientExceptionInterface $httpClientException) {
            fclose($handle);

            throw new ImportFailedException(\sprintf('%s download failed: %s', $this->label, $this->redacted($httpClientException->getMessage())), 0, $httpClientException);
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function redacted(string $message): string
    {
        return '' === $this->secret ? $message : str_replace([$this->secret, rawurlencode($this->secret)], '[redacted]', $message);
    }
}
