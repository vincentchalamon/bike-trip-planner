<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class RouteFetcherRegistry implements RouteFetcherRegistryInterface
{
    /** @var list<RouteFetcherInterface> */
    private array $fetchers;

    /**
     * @param iterable<RouteFetcherInterface> $fetchers
     */
    public function __construct(
        #[AutowireIterator('app.route_fetcher')]
        iterable $fetchers,
    ) {
        $this->fetchers = iterator_to_array($fetchers, false);
    }

    /**
     * @throws \RuntimeException When no fetcher supports the given URL
     */
    public function get(string $url): RouteFetcherInterface
    {
        foreach ($this->fetchers as $fetcher) {
            if ($fetcher->supports($url)) {
                return $fetcher;
            }
        }

        throw new \RuntimeException('No route fetcher supports the provided URL. Supported formats: Komoot Tour, Komoot Collection, Google My Maps.');
    }
}
