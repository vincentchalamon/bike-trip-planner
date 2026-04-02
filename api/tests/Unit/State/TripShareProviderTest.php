<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\TripShareProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class TripShareProviderTest extends TestCase
{
    private MockObject&TripShareRepositoryInterface $repository;

    private TripShareProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->repository = $this->createMock(TripShareRepositoryInterface::class);
        $this->provider = new TripShareProvider($this->repository);
    }

    #[Test]
    public function itReturnsActiveShare(): void
    {
        $share = new TripShare();
        $this->repository->expects($this->once())->method('findActiveByTrip')
            ->with('trip-uuid')
            ->willReturn($share);

        $result = $this->provider->provide(new Get(), ['tripId' => 'trip-uuid']);

        self::assertSame($share, $result);
    }

    #[Test]
    public function itWorksForDeleteOperation(): void
    {
        $share = new TripShare();
        $this->repository->expects($this->once())->method('findActiveByTrip')
            ->with('trip-uuid')
            ->willReturn($share);

        $result = $this->provider->provide(new Delete(), ['tripId' => 'trip-uuid']);

        self::assertSame($share, $result);
    }

    #[Test]
    public function itThrowsNotFoundWhenNoActiveShare(): void
    {
        $this->repository->expects($this->once())->method('findActiveByTrip')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['tripId' => 'trip-uuid']);
    }

    #[Test]
    public function itThrowsNotFoundWhenTripIdMissing(): void
    {
        $this->repository->expects($this->never())->method('findActiveByTrip');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), []);
    }

    #[Test]
    public function itAcceptsUuidObjectAsTripId(): void
    {
        $tripId = Uuid::v7();
        $share = new TripShare();
        $this->repository->expects($this->once())->method('findActiveByTrip')
            ->with((string) $tripId)
            ->willReturn($share);

        $result = $this->provider->provide(new Get(), ['tripId' => $tripId]);

        self::assertSame($share, $result);
    }
}
