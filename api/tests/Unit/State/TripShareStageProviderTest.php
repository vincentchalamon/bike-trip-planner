<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\SharedTripResolver;
use App\State\TripShareStageProvider;
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
final class TripShareStageProviderTest extends TestCase
{
    private MockObject&TripShareRepositoryInterface $repository;

    /** @var MockObject&ProviderInterface<Stage> */
    private MockObject $stageProvider;

    private TripShareStageProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->repository = $this->createMock(TripShareRepositoryInterface::class);
        $this->stageProvider = $this->createMock(ProviderInterface::class);
        $this->provider = new TripShareStageProvider($this->resolver(), $this->stageProvider);
    }

    #[Test]
    public function itReturnsStageForValidShortCode(): void
    {
        $trip = new TripRequest(Uuid::v7());
        $share = new TripShare(trip: $trip);

        $this->repository->expects($this->once())->method('findByShortCode')
            ->with('Ab3kX9mP')
            ->willReturn($share);

        $coord = new Coordinate(0.0, 0.0, 0.0);
        $stage = new Stage('trip-id', 1, 50.0, 100.0, $coord, $coord);
        $this->stageProvider->expects($this->once())->method('provide')->willReturn($stage);

        $result = $this->provider->provide(new Get(), ['shortCode' => 'Ab3kX9mP', 'stageId' => $stage->id]);

        self::assertSame($stage, $result);
    }

    #[Test]
    public function itThrowsNotFoundForInvalidShortCode(): void
    {
        $this->repository->expects($this->once())->method('findByShortCode')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['shortCode' => 'invalid1', 'stageId' => Uuid::v7()->toRfc4122()]);
    }

    #[Test]
    public function itThrowsNotFoundForEmptyShortCode(): void
    {
        $this->repository->expects($this->never())->method('findByShortCode');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['stageId' => Uuid::v7()->toRfc4122()]);
    }

    #[Test]
    public function itThrowsNotFoundWhenShareHasNoTrip(): void
    {
        $share = new TripShare();
        $this->repository->expects($this->once())->method('findByShortCode')->willReturn($share);
        $this->stageProvider->expects($this->never())->method('provide');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['shortCode' => 'Ab3kX9mP', 'stageId' => Uuid::v7()->toRfc4122()]);
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
