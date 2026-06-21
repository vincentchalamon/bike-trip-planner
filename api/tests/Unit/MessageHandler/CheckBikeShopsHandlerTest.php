<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckBikeShops;
use App\MessageHandler\CheckBikeShopsHandler;
use App\Osm\BikeShopRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckBikeShopsHandlerTest extends TestCase
{
    /**
     * @return list<Stage>
     */
    private function createStages(string $tripId, int $count = 6): array
    {
        $stages = [];
        for ($i = 1; $i <= $count; ++$i) {
            $stages[] = new Stage(
                tripId: $tripId,
                dayNumber: $i,
                distance: 80.0,
                elevation: 500.0,
                startPoint: new Coordinate(48.0, 2.0),
                endPoint: new Coordinate(48.5, 2.5),
            );
        }

        return $stages;
    }

    /**
     * @param list<array{name: ?string, lat: float, lon: float, hasRepair: bool}> $shops
     */
    private function bikeShopRepository(array $shops): BikeShopRepositoryInterface
    {
        $repository = $this->createStub(BikeShopRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturnCallback(
            static function (array $route, int $radiusMeters) use ($shops): array {
                self::assertSame(2000, $radiusMeters, 'findInCorridor must use the 2 km corridor radius');

                return $shops;
            },
        );

        return $repository;
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        BikeShopRepositoryInterface $bikeShopRepository,
        GeoDistanceInterface $haversine,
        ?ComputationTrackerInterface $computationTracker = null,
    ): CheckBikeShopsHandler {
        if (!$computationTracker instanceof ComputationTrackerInterface) {
            $stub = $this->createStub(ComputationTrackerInterface::class);
            $stub->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);
            $computationTracker = $stub;
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.bike_shop.nudge' => \sprintf('No bike shop on stage %s.', $params['%stage%']),
                'alert.bike_shop.no_repair_nudge' => \sprintf('Bike shop near stage %s, but no repair.', $params['%stage%']),
                default => $id,
            },
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);

        $handler = new CheckBikeShopsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $bikeShopRepository,
            $haversine,
            $translator,
            $messageBus,
        );
        $handler->setCompletionGate(new TripCompletionGate($computationTracker, $publisher, $messageBus));

        return $handler;
    }

    private function tripStateManager(string $tripId, int $stageCount = 6): TripRequestRepositoryInterface
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($this->createStages($tripId, $stageCount));
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        return $tripStateManager;
    }

    #[Test]
    public function repairShopNearbyEmitsNoAlert(): void
    {
        $tripStateManager = $this->tripStateManager('trip-1');
        $bikeShopRepository = $this->bikeShopRepository([
            ['name' => 'Cycles Repair', 'lat' => 48.5, 'lon' => 2.5, 'hasRepair' => true],
        ]);

        // Shop is close to every stage's midpoint (endPoint: 48.5, 2.5)
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(100.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BIKE_SHOP_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $bikeShopRepository, $haversine);
        $handler(new CheckBikeShops('trip-1'));
    }

    #[Test]
    public function saleOnlyShopNearbyEmitsNoRepairNudge(): void
    {
        $tripStateManager = $this->tripStateManager('trip-1');
        $bikeShopRepository = $this->bikeShopRepository([
            ['name' => 'Cycles Sale', 'lat' => 48.5, 'lon' => 2.5, 'hasRepair' => false],
        ]);

        // Sale-only shop is close to every stage's midpoint
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(100.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BIKE_SHOP_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];
                    \assert(\is_array($alerts) && \is_array($alerts[0]));
                    $action = $alerts[0]['action'];
                    \assert(\is_array($action));
                    $payload = $action['payload'];
                    \assert(\is_array($payload));

                    $message = $alerts[0]['message'];
                    \assert(\is_string($message));

                    return 6 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && str_contains($message, 'no repair')
                        && 'navigate' === $action['kind']
                        && 48.5 === $payload['lat']
                        && 2.5 === $payload['lon'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $bikeShopRepository, $haversine);
        $handler(new CheckBikeShops('trip-1'));
    }

    #[Test]
    public function noShopNearbyEmitsStandardNudge(): void
    {
        $tripStateManager = $this->tripStateManager('trip-1');
        $bikeShopRepository = $this->bikeShopRepository([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BIKE_SHOP_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 6 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && str_contains((string) $alerts[0]['message'], 'No bike shop on stage 1')
                        && null === $alerts[0]['action'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $bikeShopRepository, $haversine);
        $handler(new CheckBikeShops('trip-1'));
    }

    #[Test]
    public function tripWithFewStagesIsSkipped(): void
    {
        // BR-06: trips of 5 days or fewer skip the check entirely, but must still mark
        // the computation done so trip generation does not hang waiting for a result.
        $tripStateManager = $this->tripStateManager('trip-1', 5);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $computationTracker = $this->createMock(ComputationTrackerInterface::class);
        $computationTracker->expects($this->once())
            ->method('markDone')
            ->with('trip-1', ComputationName::BIKE_SHOPS);

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->bikeShopRepository([['name' => 'Cycles Repair', 'lat' => 48.5, 'lon' => 2.5, 'hasRepair' => true]]),
            $this->createStub(GeoDistanceInterface::class),
            $computationTracker,
        );
        $handler(new CheckBikeShops('trip-1'));
    }
}
