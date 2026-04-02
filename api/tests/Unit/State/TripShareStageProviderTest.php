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
use App\State\TripShareStageProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

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
        $this->provider = new TripShareStageProvider($this->repository, $this->stageProvider);
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

        $result = $this->provider->provide(new Get(), ['shortCode' => 'Ab3kX9mP', 'index' => 0]);

        self::assertSame($stage, $result);
    }

    #[Test]
    public function itThrowsNotFoundForInvalidShortCode(): void
    {
        $this->repository->expects($this->once())->method('findByShortCode')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['shortCode' => 'invalid1', 'index' => 0]);
    }

    #[Test]
    public function itThrowsNotFoundForEmptyShortCode(): void
    {
        $this->repository->expects($this->never())->method('findByShortCode');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['index' => 0]);
    }
}
