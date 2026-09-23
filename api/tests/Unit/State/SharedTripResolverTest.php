<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\SharedTripResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * The throttle on the anonymous share surface.
 *
 * Proven here rather than end to end: the rate-limiter pool falls back to an array adapter
 * under test, one per process, so exhausting a per-IP budget through the HTTP stack would
 * spend it for every other `/s/*` case in the same run.
 */
#[AllowMockObjectsWithoutExpectations]
final class SharedTripResolverTest extends TestCase
{
    private MockObject&TripShareRepositoryInterface $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->repository = $this->createMock(TripShareRepositoryInterface::class);
    }

    #[Test]
    public function aCallerPastItsBudgetIsRefused(): void
    {
        $trip = new TripRequest(Uuid::v7());
        $this->repository->method('findByShortCode')->willReturn(new TripShare(trip: $trip));

        $resolver = $this->resolver(limit: 1, ip: '203.0.113.7');

        self::assertSame((string) $trip->id, $resolver->resolve('Ab3kX9mP'));

        $this->expectException(TooManyRequestsHttpException::class);
        $resolver->resolve('Ab3kX9mP');
    }

    /**
     * The budget is spent before the code is looked up: a caller walking short codes must not
     * get unlimited attempts just because every one of them misses.
     */
    #[Test]
    public function aMissingCodeStillCostsTheCaller(): void
    {
        $this->repository->method('findByShortCode')->willReturn(null);

        $resolver = $this->resolver(limit: 1, ip: '203.0.113.7');

        try {
            $resolver->resolve('Ab3kX9mP');
            self::fail('expected a 404');
        } catch (NotFoundHttpException) {
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $resolver->resolve('Ab3kX9mP');
    }

    /**
     * A caller whose IP cannot be resolved is throttled with the others, not exempted.
     *
     * Failing open here is reachable by misconfiguring trusted proxies, and it would remove
     * the guard from exactly the endpoint it was added for. Not being able to tell callers
     * apart is a reason to put them in one bucket, not a reason to stop counting.
     */
    #[Test]
    public function aCallerWithNoResolvableIpIsStillThrottled(): void
    {
        $this->repository->method('findByShortCode')->willReturn(new TripShare(trip: new TripRequest(Uuid::v7())));

        $resolver = $this->resolver(limit: 1, ip: null);
        $resolver->resolve('Ab3kX9mP');

        $this->expectException(TooManyRequestsHttpException::class);
        $resolver->resolve('Ab3kX9mP');
    }

    private function resolver(int $limit, ?string $ip): SharedTripResolver
    {
        $stack = new RequestStack();

        if (null !== $ip) {
            $request = Request::create('/s/Ab3kX9mP');
            $request->server->set('REMOTE_ADDR', $ip);
            $stack = new RequestStack([$request]);
        }

        return new SharedTripResolver(
            $this->repository,
            $stack,
            new RateLimiterFactory(
                ['id' => 'shared_trip', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '60 seconds'],
                new InMemoryStorage(),
            ),
        );
    }
}
