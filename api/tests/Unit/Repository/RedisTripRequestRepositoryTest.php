<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Enum\AlertType;
use App\Llm\Dto\StageAiAnalysis;
use App\Repository\RedisTripRequestRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RedisTripRequestRepository::class)]
#[AllowMockObjectsWithoutExpectations]
final class RedisTripRequestRepositoryTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;

    private LockFactory&MockObject $lockFactory;

    private RedisTripRequestRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->repository = new RedisTripRequestRepository($this->cache, $this->lockFactory);
    }

    #[Test]
    public function updateTripAiOverviewPersistsToRedisWhenRequestExists(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $request = new TripRequest(Uuid::fromString($tripId));
        $overview = [
            'narrative' => 'global',
            'patterns' => ['p1'],
            'recommendations' => ['r1'],
            'crossStageAlerts' => [],
            'model' => 'llama3.1:8b',
            'promptVersion' => 1,
            'generatedAt' => '2026-05-11T10:00:00+00:00',
        ];

        $readItem = $this->createMock(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn($request);
        $readItem->method('expiresAfter')->willReturnSelf();

        $writeItem = $this->createMock(CacheItemInterface::class);
        $writeItem->expects(self::once())
            ->method('set')
            ->with(self::callback(static fn (TripRequest $stored): bool => $overview === $stored->aiOverviewData));
        $writeItem->method('expiresAfter')->willReturnSelf();

        // getItem is called twice: once in getRequest (read), once in set() (write)
        $this->cache->expects(self::exactly(2))
            ->method('getItem')
            ->with(\sprintf('trip.%s.request', $tripId))
            ->willReturnOnConsecutiveCalls($readItem, $writeItem);

        $this->cache->expects(self::atLeastOnce())->method('save');

        $this->repository->updateTripAiOverview($tripId, $overview);
    }

    #[Test]
    public function updateTripAiOverviewIsNoopWhenRequestMissing(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $missing = $this->createMock(CacheItemInterface::class);
        $missing->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($missing);

        $this->cache->expects(self::never())->method('save');

        $this->repository->updateTripAiOverview($tripId, null);
    }

    #[Test]
    public function updateStageAiAnalysisAssignsDtoUnderLock(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $stage = new Stage(
            tripId: $tripId,
            dayNumber: 2,
            distance: 50.0,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.1, 2.1),
        );

        $analysis = [
            'narrative' => 'OK',
            'insights' => ['i1'],
            'suggestions' => ['s1'],
            'model' => 'llama3.1:8b',
            'promptVersion' => 1,
            'generatedAt' => '2026-05-11T10:00:00+00:00',
        ];

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->with(true)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $this->lockFactory->expects(self::once())
            ->method('createLock')
            ->with(self::stringContains($tripId), 5)
            ->willReturn($lock);

        $readItem = $this->createMock(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn([$stage]);
        $readItem->method('expiresAfter')->willReturnSelf();

        $writeItem = $this->createMock(CacheItemInterface::class);
        $writeItem->expects(self::once())
            ->method('set')
            ->with(self::callback(static fn (array $stages): bool => 1 === \count($stages)
                && $stages[0]->aiAnalysis instanceof StageAiAnalysis
                && $analysis['narrative'] === $stages[0]->aiAnalysis->narrative));
        $writeItem->method('expiresAfter')->willReturnSelf();

        // getItem is called twice: once in getStages (read), once in storeStages->set (write)
        $this->cache->method('getItem')
            ->willReturnOnConsecutiveCalls($readItem, $writeItem);
        $this->cache->expects(self::atLeastOnce())->method('save');

        $this->repository->updateStageAiAnalysis($tripId, 2, $analysis);
    }

    /**
     * Regression for the persistence race (recette #649): a per-column update must
     * read the stage fresh and write back only its own column, so it cannot wipe a
     * sibling column (here: weather) a concurrent enrichment handler already persisted.
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

        $alert = new Alert(type: AlertType::WARNING, message: 'steep gradient');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->with(true)->willReturn(true);
        $lock->expects(self::once())->method('release');
        $this->lockFactory->expects(self::once())
            ->method('createLock')
            ->with(self::stringContains($tripId), 5)
            ->willReturn($lock);

        $readItem = $this->createMock(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn([$stage]);
        $readItem->method('expiresAfter')->willReturnSelf();

        $writeItem = $this->createMock(CacheItemInterface::class);
        $writeItem->expects(self::once())
            ->method('set')
            ->with(self::callback(static fn (array $stages): bool => 1 === \count($stages)
                // the alert is written...
                && [$alert] === $stages[0]->alerts
                // ...without wiping the weather a sibling handler already persisted.
                && $stages[0]->weather instanceof WeatherForecast
                && '10d' === $stages[0]->weather->icon));
        $writeItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturnOnConsecutiveCalls($readItem, $writeItem);
        $this->cache->expects(self::atLeastOnce())->method('save');

        $this->repository->updateStageAlerts($tripId, 1, [$alert]);
    }
}
