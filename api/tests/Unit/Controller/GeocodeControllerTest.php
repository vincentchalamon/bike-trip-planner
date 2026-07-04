<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\GeocodeController;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GeocodeControllerTest extends TestCase
{
    #[Test]
    public function searchIsRateLimited(): void
    {
        // Geocode rate-limit (2026-07 audit): on a cache miss, the outbound Nominatim
        // call is throttled per user; an exhausted limiter rejects with 429 first.
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

        $controller = new GeocodeController(
            $this->createStub(HttpClientInterface::class),
            $cache,
            $security,
            $limiter,
        );

        $this->expectException(TooManyRequestsHttpException::class);
        $controller->search(new Request(['q' => 'paris']));
    }
}
