<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripDetail;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\TripShareShortCodeProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class TripShareShortCodeProviderTest extends TestCase
{
    private MockObject&TripShareRepositoryInterface $repository;

    /** @var MockObject&ProviderInterface<TripDetail> */
    private MockObject $tripDetailProvider;

    private TripShareShortCodeProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->repository = $this->createMock(TripShareRepositoryInterface::class);
        $this->tripDetailProvider = $this->createMock(ProviderInterface::class);
        $this->provider = new TripShareShortCodeProvider($this->repository, $this->tripDetailProvider);
    }

    #[Test]
    public function itReturnsTripDetailForValidShortCode(): void
    {
        $trip = new TripRequest(Uuid::v7());
        $share = new TripShare(trip: $trip);

        $this->repository->expects($this->once())->method('findByShortCode')
            ->with('Ab3kX9mP')
            ->willReturn($share);

        $detail = new TripDetail(
            id: (string) $trip->id,
            title: null,
            sourceUrl: null,
            startDate: null,
            endDate: null,
            fatigueFactor: 0.9,
            elevationPenalty: 50.0,
            maxDistancePerDay: 80.0,
            averageSpeed: 15.0,
            ebikeMode: false,
            departureHour: 8,
            enabledAccommodationTypes: [],
            isLocked: false,
            stages: [],
        );
        $this->tripDetailProvider->expects($this->once())->method('provide')->willReturn($detail);

        $result = $this->provider->provide(new Get(), ['shortCode' => 'Ab3kX9mP']);

        self::assertSame($detail, $result);
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
        $this->tripDetailProvider->expects($this->never())->method('provide');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['shortCode' => 'Ab3kX9mP']);
    }
}
