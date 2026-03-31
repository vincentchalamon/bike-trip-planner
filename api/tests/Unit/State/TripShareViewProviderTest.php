<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripDetail;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\TripShareViewProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(TripShareViewProvider::class)]
#[AllowMockObjectsWithoutExpectations]
final class TripShareViewProviderTest extends TestCase
{
    private MockObject&TripShareRepositoryInterface $repository;

    /** @var MockObject&ProviderInterface<TripDetail> */
    private MockObject $tripDetailProvider;

    private TripShareViewProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->repository = $this->createMock(TripShareRepositoryInterface::class);
        $this->tripDetailProvider = $this->createMock(ProviderInterface::class);

        $this->provider = new TripShareViewProvider(
            $this->repository,
            $this->tripDetailProvider,
        );
    }

    /** @return array<string, mixed> */
    private function makeContext(string $token): array
    {
        return ['request' => new Request(query: ['token' => $token])];
    }

    #[Test]
    public function validShareReturnsTripDetail(): void
    {
        $tripId = 'test-trip-uuid';
        $token = str_repeat('a', 64);

        $share = $this->createStub(TripShare::class);
        $this->repository->expects($this->once())->method('findValidShare')->with($tripId, $token)->willReturn($share);

        $expectedDetail = new TripDetail(
            id: $tripId,
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
        $this->tripDetailProvider
            ->expects($this->once())
            ->method('provide')
            ->with($this->anything(), ['id' => $tripId], $this->anything())
            ->willReturn($expectedDetail);

        $result = $this->provider->provide(new Get(), ['tripId' => $tripId], $this->makeContext($token));

        $this->assertSame($expectedDetail, $result);
    }

    #[Test]
    public function missingTokenThrowsNotFound(): void
    {
        $this->repository->expects($this->never())->method('findValidShare');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['tripId' => 'some-trip-id'], $this->makeContext(''));
    }

    #[Test]
    public function missingTripIdThrowsNotFound(): void
    {
        $this->repository->expects($this->never())->method('findValidShare');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), [], $this->makeContext(str_repeat('b', 64)));
    }

    #[Test]
    public function invalidShareThrowsNotFound(): void
    {
        $tripId = 'test-trip-uuid';
        $token = str_repeat('c', 64);

        $this->repository->expects($this->once())->method('findValidShare')->with($tripId, $token)->willReturn(null);

        $this->tripDetailProvider->expects($this->never())->method('provide');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['tripId' => $tripId], $this->makeContext($token));
    }

    #[Test]
    public function missingRequestInContextThrowsNotFound(): void
    {
        $this->repository->expects($this->never())->method('findValidShare');

        $this->expectException(NotFoundHttpException::class);
        $this->provider->provide(new Get(), ['tripId' => 'some-trip-id'], []);
    }
}
