<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\SharedTripResolver;
use App\State\TripShareGpxProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class TripShareGpxProviderTest extends TestCase
{
    private MockObject&TripShareRepositoryInterface $repository;

    /** @var MockObject&ProviderInterface<Trip> */
    private MockObject $tripGpxProvider;

    private TripShareGpxProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->repository = $this->createMock(TripShareRepositoryInterface::class);
        $this->tripGpxProvider = $this->createMock(ProviderInterface::class);
        $this->provider = new TripShareGpxProvider($this->resolver(), $this->tripGpxProvider);
    }

    #[Test]
    public function itReturnsTripForValidShortCode(): void
    {
        $tripId = Uuid::v7();
        $trip = new TripRequest($tripId);
        $share = new TripShare(trip: $trip);

        $this->repository->expects($this->once())->method('findByShortCode')
            ->with('Ab3kX9mP')
            ->willReturn($share);

        $tripResource = new Trip((string) $tripId, computationStatus: [], isLocked: false);
        $this->tripGpxProvider->expects($this->once())->method('provide')->willReturn($tripResource);

        $result = $this->provider->provide(new Get(), ['shortCode' => 'Ab3kX9mP']);

        self::assertSame($tripResource, $result);
    }

    #[Test]
    public function itThrowsNotFoundForInvalidShortCode(): void
    {
        $this->repository->expects($this->once())->method('findByShortCode')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['shortCode' => 'invalid1']);
    }

    #[Test]
    public function itThrowsNotFoundForEmptyShortCode(): void
    {
        $this->repository->expects($this->never())->method('findByShortCode');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), []);
    }

    #[Test]
    public function itThrowsNotFoundWhenShareHasNoTrip(): void
    {
        $share = new TripShare();
        $this->repository->expects($this->once())->method('findByShortCode')->willReturn($share);
        $this->tripGpxProvider->expects($this->never())->method('provide');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['shortCode' => 'Ab3kX9mP']);
    }

    /**
     * A real resolver over the mocked repository, not a double of it.
     *
     * Resolving a short code moved into {@see SharedTripResolver}; these cases still assert
     * what they always did — that a bad code is a 404 — and they now do it through the code
     * that actually decides. With no request on the stack there is no client IP, so the rate
     * limiter is never consulted.
     */
    private function resolver(): SharedTripResolver
    {
        return new SharedTripResolver(
            $this->repository,
            new RequestStack(),
            new RateLimiterFactory(
                ['id' => 'shared_trip', 'policy' => 'fixed_window', 'limit' => 60, 'interval' => '60 seconds'],
                new InMemoryStorage(),
            ),
        );
    }
}
