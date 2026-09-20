<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Enum\AlertGroup;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Repository\RedisTripRequestRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RedisTripRequestRepository::class)]
#[AllowMockObjectsWithoutExpectations]
final class RedisTripRequestRepositoryTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;

    private RedisTripRequestRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->repository = new RedisTripRequestRepository($this->cache);
    }

    /**
     * Regression for the persistence race (recette #649): a per-column update must
     * read the stage fresh and write back only its own column, so it cannot wipe a
     * sibling column (here: weather) a concurrent enrichment handler already persisted.
     *
     * Serialisation against a concurrent whole-collection write is not this class's job
     * any more — LockingTripRequestRepository decorates it and holds the per-trip lock.
     */
    #[Test]
    public function updateStageAlertsPreservesSiblingColumns(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $stage = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 50.0,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.1, 2.1),
        );
        // A sibling handler (FetchWeather) already wrote weather on this stage.
        $stage->weather = new WeatherForecast(
            icon: '10d',
            description: 'Rain',
            tempMin: 12.0,
            tempMax: 18.0,
            windSpeed: 10.0,
            windDirection: 'N',
            precipitationProbability: 80,
            humidity: 70,
            comfortIndex: 90,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
        );

        $alert = new Alert(code: AlertCode::STEEP_GRADIENT, type: AlertType::WARNING, messageKey: 'alert.steep_gradient.warning');

        $readItem = $this->createMock(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn([$stage]);
        $readItem->method('expiresAfter')->willReturnSelf();

        $writeItem = $this->createMock(CacheItemInterface::class);
        $writeItem->expects(self::once())
            ->method('set')
            ->with(self::callback(static fn (array $stages): bool => 1 === \count($stages)
                // the alert is written, tagged with the group that owns it...
                && [['group' => 'terrain', 'code' => $alert->code?->value, 'type' => $alert->type->value, 'message' => $alert->messageKey]] === $stages[0]->alerts
                // ...without wiping the weather a sibling handler already persisted.
                && $stages[0]->weather instanceof WeatherForecast
                && '10d' === $stages[0]->weather->icon));
        $writeItem->method('expiresAfter')->willReturnSelf();

        // storeStages() also bumps the trip version, so the pool is asked for more than
        // the two stage items: route by key rather than by call order.
        $versionItem = $this->createMock(CacheItemInterface::class);
        $versionItem->method('isHit')->willReturn(false);
        $versionItem->method('expiresAfter')->willReturnSelf();

        $stageReads = 0;
        $this->cache->method('getItem')->willReturnCallback(
            static function (string $key) use ($readItem, $writeItem, $versionItem, &$stageReads): CacheItemInterface {
                if (str_ends_with($key, '.version')) {
                    return $versionItem;
                }

                return 0 === $stageReads++ ? $readItem : $writeItem;
            },
        );
        $this->cache->expects(self::atLeastOnce())->method('save');

        $this->repository->updateStageAlertsForGroup($tripId, $stage->id, AlertGroup::TERRAIN, [['code' => $alert->code?->value, 'type' => $alert->type->value, 'message' => $alert->messageKey]]);
    }

    #[Test]
    public function getStageGeometryReturnsPointsInTravelOrder(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $stage = new Stage(
            tripId: $tripId,
            dayNumber: 2,
            distance: 40.0,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.2, 2.2),
            geometry: [
                new Coordinate(48.0, 2.0, 100.0),
                new Coordinate(48.1, 2.1, 110.0),
                new Coordinate(48.2, 2.2, 120.0),
            ],
        );

        $readItem = $this->createMock(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn([$stage]);
        $readItem->method('expiresAfter')->willReturnSelf();
        $this->cache->expects(self::atLeastOnce())
            ->method('getItem')
            ->with(\sprintf('trip.%s.stages', $tripId))
            ->willReturn($readItem);

        // ele is dropped: DetourCalculator works in 2D.
        self::assertSame(
            [
                ['lat' => 48.0, 'lon' => 2.0],
                ['lat' => 48.1, 'lon' => 2.1],
                ['lat' => 48.2, 'lon' => 2.2],
            ],
            $this->repository->getStageGeometry($tripId, $stage->id),
        );
    }

    #[Test]
    public function getStageGeometryReturnsNullForUnknownStage(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $stage = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 40.0,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.2, 2.2),
            geometry: [new Coordinate(48.0, 2.0, 100.0)],
        );

        $readItem = $this->createMock(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn([$stage]);
        $readItem->method('expiresAfter')->willReturnSelf();
        $this->cache->method('getItem')->willReturn($readItem);

        self::assertNull($this->repository->getStageGeometry($tripId, Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function getStageGeometryReturnsNullWhenTripMissing(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $missing = $this->createMock(CacheItemInterface::class);
        $missing->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($missing);

        self::assertNull($this->repository->getStageGeometry($tripId, Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function getStageGeometryReturnsNullForEmptyGeometry(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $stage = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 40.0,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.2, 2.2),
        );

        $readItem = $this->createMock(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn([$stage]);
        $readItem->method('expiresAfter')->willReturnSelf();
        $this->cache->method('getItem')->willReturn($readItem);

        self::assertNull($this->repository->getStageGeometry($tripId, $stage->id));
    }
}
