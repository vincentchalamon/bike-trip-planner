<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.route_fetcher')]
interface RouteFetcherInterface
{
    public function supports(string $url): bool;

    /**
     * @throws RuntimeException When the route cannot be fetched or parsed
     */
    public function fetch(string $url): RouteFetchResult;
}
