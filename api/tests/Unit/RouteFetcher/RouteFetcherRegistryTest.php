<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteFetcher;

use ArrayIterator;
use RuntimeException;
use App\RouteFetcher\RouteFetcherInterface;
use App\RouteFetcher\RouteFetcherRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteFetcherRegistryTest extends TestCase
{
    #[Test]
    public function getReturnsMatchingFetcher(): void
    {
        $fetcher = $this->createStub(RouteFetcherInterface::class);
        $fetcher->method('supports')->willReturn(true);

        $registry = new RouteFetcherRegistry(new ArrayIterator([$fetcher]));

        self::assertSame($fetcher, $registry->get('https://example.com'));
    }

    #[Test]
    public function getThrowsWhenNoFetcherMatches(): void
    {
        $fetcher = $this->createStub(RouteFetcherInterface::class);
        $fetcher->method('supports')->willReturn(false);

        $registry = new RouteFetcherRegistry(new ArrayIterator([$fetcher]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strava Route, RideWithGPS Route');

        $registry->get('https://unsupported.example.com');
    }
}
