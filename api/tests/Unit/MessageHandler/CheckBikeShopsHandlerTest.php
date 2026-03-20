<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckBikeShops;
use App\MessageHandler\CheckBikeShopsHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
        GeoDistanceInterface $haversine,
    ): CheckBikeShopsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.bike_shop.nudge' => \sprintf('No bike shop on stage %s.', $params['%stage%']),
                'alert.bike_shop.no_repair_nudge' => \sprintf('Bike shop near stage %s, but no repair.', $params['%stage%']),
                default => $id,
            },
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new CheckBikeShopsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $scanner,
            $queryBuilder,
            $haversine,
            $translator,
        );
    }

    #[Test]
    public function shopWithRepairServiceEmitsNoAlert(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBikeShopQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 48.5,
                    'lon' => 2.5,
                    'tags' => ['shop' => 'bicycle', 'service:bicycle:repair' => 'yes'],
                ],
            ],
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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckBikeShops('trip-1'));
    }

    #[Test]
    public function shopWithoutRepairServiceEmitsNoRepairNudge(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBikeShopQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 48.5,
                    'lon' => 2.5,
                    'tags' => ['shop' => 'bicycle'],
                ],
            ],
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

                    return 6 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && str_contains((string) $alerts[0]['message'], 'no repair');
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckBikeShops('trip-1'));
    }

    #[Test]
    public function noShopNearbyEmitsStandardNudge(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBikeShopQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

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
                        && str_contains((string) $alerts[0]['message'], 'No bike shop on stage 1');
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckBikeShops('trip-1'));
    }

    #[Test]
    public function repairServiceWithoutBicycleShopTagEmitsNoAlert(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBikeShopQuery')->willReturn('query');

        // Associative workshop: has repair tag but no shop=bicycle
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 48.5,
                    'lon' => 2.5,
                    'tags' => ['service:bicycle:repair' => 'yes'],
                ],
            ],
        ]);

        // Repair workshop is close to every stage's midpoint
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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckBikeShops('trip-1'));
    }
}
