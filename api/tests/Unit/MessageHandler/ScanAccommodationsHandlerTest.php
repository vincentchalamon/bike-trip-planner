<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Accommodation\AccommodationMetadataExtractor;
use App\Accommodation\SeasonalityCheckerInterface;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\PricingHeuristicEngine;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAccommodations;
use App\MessageHandler\ScanAccommodationsHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ScanAccommodationsHandlerTest extends TestCase
{
    private function createStage(string $tripId, float $endLat = 48.5, float $endLon = 2.5): Stage
    {
        return new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate($endLat, $endLon),
        );
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
        GeoDistanceInterface $haversine,
        GeometryDistributorInterface $distributor,
    ): ScanAccommodationsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $pricingEngine = new PricingHeuristicEngine();

        $metadataExtractor = new AccommodationMetadataExtractor();

        $seasonalityChecker = $this->createStub(SeasonalityCheckerInterface::class);
        $seasonalityChecker->method('isLikelyOpen')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => $id.': '.json_encode($params),
        );

        $scraperClient = $this->createStub(HttpClientInterface::class);

        $logger = $this->createStub(LoggerInterface::class);

        return new ScanAccommodationsHandler(
            $computationTracker,
            $publisher,
            $tripStateManager,
            $scanner,
            $queryBuilder,
            $pricingEngine,
            $haversine,
            $distributor,
            $metadataExtractor,
            $seasonalityChecker,
            $translator,
            $scraperClient,
            $logger,
        );
    }

    #[Test]
    public function distanceToEndPointIsComputedFromAccommodationCoordinatesToStageEndPoint(): void
    {
        $stage = $this->createStage('trip-1', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $accommodationLat = 48.6;
        $accommodationLon = 2.6;

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => $accommodationLat,
                    'lon' => $accommodationLon,
                    'tags' => ['tourism' => 'hotel', 'name' => 'Hotel du Nord'],
                ],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [
                [
                    'name' => 'Hotel du Nord',
                    'type' => 'hotel',
                    'lat' => $accommodationLat,
                    'lon' => $accommodationLon,
                    'priceMin' => 50.0,
                    'priceMax' => 120.0,
                    'isExact' => false,
                    'url' => null,
                    'tagCount' => 2,
                    'hasWebsite' => false,
                    'tags' => ['tourism' => 'hotel', 'name' => 'Hotel du Nord'],
                ],
            ],
        ]);

        // Haversine must be called with accommodation coordinates first, then stage endpoint
        $haversine = $this->createMock(GeoDistanceInterface::class);
        $haversine->expects($this->once())
            ->method('inKilometers')
            ->with($accommodationLat, $accommodationLon, 48.5, 2.5)
            ->willReturn(12.3);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $accommodations = $data['accommodations'];

                    return 1 === \count($accommodations)
                        && 12.3 === $accommodations[0]['distanceToEndPoint'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-1'));
    }

    #[Test]
    public function distanceToEndPointIsPresentInPublishedMercurePayload(): void
    {
        $stage = $this->createStage('trip-1', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'tags' => ['tourism' => 'camp_site', 'name' => 'Camping du Lac'],
                ],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [
                [
                    'name' => 'Camping du Lac',
                    'type' => 'camp_site',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'priceMin' => 8.0,
                    'priceMax' => 25.0,
                    'isExact' => false,
                    'url' => null,
                    'tagCount' => 2,
                    'hasWebsite' => false,
                    'tags' => ['tourism' => 'camp_site', 'name' => 'Camping du Lac'],
                ],
            ],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(5.7);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    if (0 === $data['stageIndex'] && 1 === \count($data['accommodations'])) {
                        $acc = $data['accommodations'][0];

                        return array_key_exists('distanceToEndPoint', $acc) && 5.7 === $acc['distanceToEndPoint'];
                    }

                    return false;
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-1'));
    }

    #[Test]
    public function zeroDistanceAccommodationPublishesZeroPointZero(): void
    {
        // Accommodation at the exact same coordinates as the stage endpoint
        $endLat = 48.5;
        $endLon = 2.5;
        $stage = $this->createStage('trip-1', $endLat, $endLon);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => $endLat,
                    'lon' => $endLon,
                    'tags' => ['tourism' => 'hostel', 'name' => 'Hostel Central'],
                ],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [
                [
                    'name' => 'Hostel Central',
                    'type' => 'hostel',
                    'lat' => $endLat,
                    'lon' => $endLon,
                    'priceMin' => 20.0,
                    'priceMax' => 35.0,
                    'isExact' => false,
                    'url' => null,
                    'tagCount' => 2,
                    'hasWebsite' => false,
                    'tags' => ['tourism' => 'hostel', 'name' => 'Hostel Central'],
                ],
            ],
        ]);

        // haversine returns 0.0 when accommodation is at the same location as endpoint
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(0.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $accommodations = $data['accommodations'];

                    return 1 === \count($accommodations)
                        && 0.0 === $accommodations[0]['distanceToEndPoint'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-1'));
    }

    #[Test]
    public function buildAccommodationQueryReceivesStageEndPoints(): void
    {
        $stage = $this->createStage('trip-1', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilderInterface::class);
        $queryBuilder->expects($this->once())
            ->method('buildAccommodationQuery')
            ->with(
                $this->callback(static fn (array $points): bool => 1 === \count($points)
                    && 48.5 === $points[0]->lat
                    && 2.5 === $points[0]->lon),
                $this->anything(),
            )
            ->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-1'));
    }

    #[Test]
    public function secondDispatchDoesNotAccumulateAccommodations(): void
    {
        $stage = $this->createStage('trip-2', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.6, 'lon' => 2.6, 'tags' => ['tourism' => 'hotel', 'name' => 'Hotel A']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel A', 'type' => 'hotel', 'lat' => 48.6, 'lon' => 2.6,
                'priceMin' => 50.0, 'priceMax' => 100.0, 'isExact' => false,
                'url' => null, 'tagCount' => 2, 'hasWebsite' => false, 'tags' => []]],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(1.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->exactly(2))
            ->method('publish')
            ->with(
                'trip-2',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static fn (array $d): bool => 1 === \count($d['accommodations']))
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-2'));
        $handler(new ScanAccommodations('trip-2'));

        $this->assertCount(1, $stage->accommodations);
    }

    #[Test]
    public function expandScanAccumulatesAccommodations(): void
    {
        $stage = $this->createStage('trip-3', 48.5, 2.5);

        // Pre-populate the stage with one existing accommodation
        $existing = new Accommodation(
            name: 'Camping du Lac',
            type: 'camp_site',
            lat: 48.4,
            lon: 2.4,
            estimatedPriceMin: 8.0,
            estimatedPriceMax: 25.0,
            isExactPrice: false,
            distanceToEndPoint: 3.0,
        );
        $stage->accommodations = [$existing];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        // Scanner returns a new accommodation (different coordinates — not a duplicate)
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.7, 'lon' => 2.7, 'tags' => ['tourism' => 'hotel', 'name' => 'Hotel du Nord']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel du Nord', 'type' => 'hotel', 'lat' => 48.7, 'lon' => 2.7,
                'priceMin' => 60.0, 'priceMax' => 120.0, 'isExact' => false,
                'url' => null, 'tagCount' => 2, 'hasWebsite' => false, 'tags' => []]],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(5.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-3',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    // Both the existing and the new accommodation must be present
                    $accommodations = $data['accommodations'];
                    if (2 !== \count($accommodations)) {
                        return false;
                    }

                    $names = array_column($accommodations, 'name');

                    return \in_array('Camping du Lac', $names, true)
                        && \in_array('Hotel du Nord', $names, true);
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-3', isExpandScan: true));

        // Stage accommodations must contain both entries after the expand scan
        $this->assertCount(2, $stage->accommodations);
    }
}
