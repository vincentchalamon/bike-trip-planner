<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\User;
use App\Service\NominatimThrottle;
use App\State\GeocodeSearchProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moved here with the code it tests, when place search left
 * {@see \App\Controller\GeocodeController} to become an API Platform operation.
 *
 * The functional test covers both transports end to end; these two cover what a functional
 * test cannot reach without actually exhausting a limiter or leaving the query out.
 */
final class GeocodeSearchProviderTest extends TestCase
{
    /**
     * Geocode rate-limit (2026-07 audit): on a cache miss the outbound Nominatim call is
     * throttled per user, and an exhausted limiter refuses before the request goes out.
     */
    #[Test]
    public function anExhaustedLimiterRefusesBeforeCallingOut(): void
    {
        $user = new User('geo@example.com');

        $limiter = new RateLimiterFactory(
            ['id' => 'geocode', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $limiter->create($user->getUserIdentifier())->consume();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $missItem = $this->createStub(CacheItemInterface::class);
        $missItem->method('isHit')->willReturn(false);
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($missItem);

        $provider = new GeocodeSearchProvider(
            $this->createStub(HttpClientInterface::class),
            $cache,
            new NominatimThrottle($security, $limiter),
        );

        $this->expectException(TooManyRequestsHttpException::class);
        $provider->provide(new GetCollection(), [], ['filters' => ['q' => 'paris']]);
    }

    /**
     * Refused rather than answered with an empty list: a model handed nothing back concludes
     * the place does not exist and moves on, instead of noticing it forgot the argument.
     */
    #[Test]
    public function anEmptySearchTermIsRefused(): void
    {
        $provider = new GeocodeSearchProvider(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(CacheItemPoolInterface::class),
            new NominatimThrottle(
                $this->createStub(Security::class),
                new RateLimiterFactory(
                    ['id' => 'geocode', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
                    new InMemoryStorage(),
                ),
            ),
        );

        $this->expectException(BadRequestHttpException::class);
        $provider->provide(new GetCollection(), [], ['filters' => ['q' => '   ']]);
    }
}
