<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\TripShareGpxProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

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
        $this->provider = new TripShareGpxProvider($this->repository, $this->tripGpxProvider);
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

        $tripResource = new Trip((string) $tripId);
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
}
