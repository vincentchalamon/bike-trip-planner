<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Accommodation\CandidateRanker;
use App\Accommodation\SeasonalityCheckerInterface;
use App\AccommodationSource\AccommodationSourceRegistry;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryBasedDistributor;
use App\Geo\GeometryDistributorInterface;
use App\Geo\HaversineDistance;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAccommodations;
use App\MessageHandler\ScanAccommodationsHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
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
        AccommodationSourceRegistry $registry,
        GeoDistanceInterface $haversine,
        GeometryDistributorInterface $distributor,
    ): ScanAccommodationsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $seasonalityChecker = $this->createStub(SeasonalityCheckerInterface::class);
        $seasonalityChecker->method('isLikelyOpen')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => $id.': '.json_encode($params),
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new ScanAccommodationsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $registry,
            $haversine,
            $distributor,
            $seasonalityChecker,
            new CandidateRanker(),
            $translator,
            $this->createStub(MessageBusInterface::class),
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

        $accommodationLat = 48.6;
        $accommodationLon = 2.6;

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([
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
                'source' => 'osm',
                'wikidataId' => null,
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
                    'source' => 'osm',
                    'wikidataId' => null,
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

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
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

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

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
                    'source' => 'osm',
                    'wikidataId' => null,
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

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-1'));
    }

    #[Test]
    public function zeroDistanceAccommodationPublishesZeroPointZero(): void
    {
        $endLat = 48.5;
        $endLon = 2.5;
        $stage = $this->createStage('trip-1', $endLat, $endLon);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

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
                    'source' => 'osm',
                    'wikidataId' => null,
                ],
            ],
        ]);

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

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-1'));
    }

    #[Test]
    public function registryReceivesStageEndPointsAndRadiusAndEnabledTypes(): void
    {
        $stage = $this->createStage('trip-1', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createMock(AccommodationSourceRegistry::class);
        $registry->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (array $points): bool => 1 === \count($points)
                    && 48.5 === $points[0]->lat
                    && 2.5 === $points[0]->lon),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
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

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel A', 'type' => 'hotel', 'lat' => 48.6, 'lon' => 2.6,
                'priceMin' => 50.0, 'priceMax' => 100.0, 'isExact' => false,
                'url' => null, 'tagCount' => 2, 'hasWebsite' => false, 'tags' => [],
                'source' => 'osm', 'wikidataId' => null]],
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

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-2'));
        $handler(new ScanAccommodations('trip-2'));

        $this->assertCount(1, $stage->accommodations);
    }

    #[Test]
    public function expandScanAccumulatesAccommodations(): void
    {
        $stage = $this->createStage('trip-3', 48.5, 2.5);

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

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel du Nord', 'type' => 'hotel', 'lat' => 48.7, 'lon' => 2.7,
                'priceMin' => 60.0, 'priceMax' => 120.0, 'isExact' => false,
                'url' => null, 'tagCount' => 2, 'hasWebsite' => false, 'tags' => [],
                'source' => 'osm', 'wikidataId' => null]],
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
                    $accommodations = $data['accommodations'];
                    if (2 !== \count($accommodations)) {
                        return false;
                    }

                    $names = array_column($accommodations, 'name');

                    return \in_array('Camping du Lac', $names, true)
                        && \in_array('Hotel du Nord', $names, true);
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-3', isExpandScan: true));

        $this->assertCount(2, $stage->accommodations);
    }

    #[Test]
    public function candidateWithWebsiteKeepsHeuristicPriceWithoutScraping(): void
    {
        // A candidate that advertises a website is no longer scraped (ADR-040):
        // it keeps the heuristic price from its source, with isExactPrice=false.
        $stage = $this->createStage('trip-no-scrape', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel With Site', 'type' => 'hotel', 'lat' => 48.6, 'lon' => 2.6,
                'priceMin' => 50.0, 'priceMax' => 100.0, 'isExact' => false,
                'url' => 'https://hotel.example.com', 'tagCount' => 3, 'hasWebsite' => true,
                'tags' => ['tourism' => 'hotel', 'name' => 'Hotel With Site', 'website' => 'https://hotel.example.com'],
                'source' => 'osm', 'wikidataId' => null]],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(2.5);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-no-scrape',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $accommodations = $data['accommodations'];

                    return 1 === \count($accommodations)
                        && 'Hotel With Site' === $accommodations[0]['name']
                        && 50.0 === $accommodations[0]['estimatedPriceMin']
                        && 100.0 === $accommodations[0]['estimatedPriceMax']
                        && false === $accommodations[0]['isExactPrice']
                        && 'https://hotel.example.com' === $accommodations[0]['url'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-no-scrape'));

        $this->assertCount(1, $stage->accommodations);
        $this->assertFalse($stage->accommodations[0]->isExactPrice);
    }

    #[Test]
    public function wildernessHutIsRecognisedAsTypeWildernessHut(): void
    {
        $stage = $this->createStage('trip-wilderness', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [
                [
                    'name' => 'Refuge du Sommet',
                    'type' => 'wilderness_hut',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'priceMin' => 0.0,
                    'priceMax' => 10.0,
                    'isExact' => false,
                    'url' => null,
                    'tagCount' => 2,
                    'hasWebsite' => false,
                    'tags' => ['tourism' => 'wilderness_hut', 'name' => 'Refuge du Sommet'],
                    'source' => 'osm',
                    'wikidataId' => null,
                ],
            ],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(1.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-wilderness',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $acc = $data['accommodations'][0] ?? null;

                    return null !== $acc
                        && 'wilderness_hut' === $acc['type']
                        && 0.0 === $acc['estimatedPriceMin']
                        && 10.0 === $acc['estimatedPriceMax'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-wilderness'));
    }

    #[Test]
    public function amenityShelterElementIsMappedToTypeShelter(): void
    {
        $stage = $this->createStage('trip-shelter', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [
                [
                    'name' => 'Lean-To Shelter',
                    'type' => 'shelter',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'priceMin' => 0.0,
                    'priceMax' => 0.0,
                    'isExact' => false,
                    'url' => null,
                    'tagCount' => 3,
                    'hasWebsite' => false,
                    'tags' => ['amenity' => 'shelter', 'shelter_type' => 'lean_to', 'name' => 'Lean-To Shelter'],
                    'source' => 'osm',
                    'wikidataId' => null,
                ],
            ],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(0.5);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-shelter',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $acc = $data['accommodations'][0] ?? null;

                    return null !== $acc
                        && 'shelter' === $acc['type']
                        && 0.0 === $acc['estimatedPriceMin']
                        && 0.0 === $acc['estimatedPriceMax'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-shelter'));
    }

    #[Test]
    public function campSiteWithBackpackYesReceivesBikepackerFriendlyPricing(): void
    {
        $stage = $this->createStage('trip-backpack', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [
                [
                    'name' => 'Wild Camp',
                    'type' => 'camp_site',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'priceMin' => 8.0,
                    'priceMax' => 15.0,
                    'isExact' => false,
                    'url' => null,
                    'tagCount' => 3,
                    'hasWebsite' => false,
                    'tags' => ['tourism' => 'camp_site', 'backpack' => 'yes', 'name' => 'Wild Camp'],
                    'source' => 'osm',
                    'wikidataId' => null,
                ],
            ],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(2.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-backpack',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $acc = $data['accommodations'][0] ?? null;

                    return null !== $acc
                        && 'camp_site' === $acc['type']
                        && 8.0 === $acc['estimatedPriceMin']
                        && 15.0 === $acc['estimatedPriceMax'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-backpack'));
    }

    #[Test]
    public function sourceFieldIsPublishedInMercurePayload(): void
    {
        $stage = $this->createStage('trip-source', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [
                [
                    'name' => 'Hotel DataTourisme',
                    'type' => 'hotel',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'priceMin' => 80.0,
                    'priceMax' => 150.0,
                    'isExact' => true,
                    'url' => 'https://hotel.example.fr',
                    'tagCount' => 0,
                    'hasWebsite' => true,
                    'tags' => [],
                    'source' => 'datatourisme',
                    'wikidataId' => null,
                ],
            ],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(1.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-source',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $acc = $data['accommodations'][0] ?? null;

                    return null !== $acc && 'datatourisme' === $acc['source'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations('trip-source'));
    }

    #[Test]
    public function retainsTheDocumentedHotelOverTheCheapestBareCandidates(): void
    {
        // Six bare shelters at €0 and one hotel with website, description and stars:
        // ranking on price alone filled every slot with the shelters and dropped the
        // hotel. Completeness ranking retains it, and the family cap leaves the
        // shelters four of the five slots (#869).
        $candidates = [];
        foreach (range(1, 6) as $i) {
            $candidates[] = $this->candidate(\sprintf('Abri %d', $i), 'shelter', priceMin: 0.0);
        }

        $candidates[] = $this->candidate(
            'Hotel Documenté',
            'hotel',
            priceMin: 90.0,
            url: 'https://hotel.example',
            hasWebsite: true,
            description: 'Hôtel de charme avec garage à vélos',
            openingHours: 'Mo-Su 07:00-22:00',
            stars: 3,
        );

        $names = $this->publishedNames('trip-ranking', $candidates);

        self::assertSame('Hotel Documenté', $names[0], 'the documented hotel must be retained, and ranked first');
        self::assertCount(5, $names);
        self::assertCount(4, array_filter($names, static fn (string $name): bool => str_starts_with($name, 'Abri ')));
    }

    #[Test]
    public function diversityGuardKeepsACampSiteAmongDocumentedHotels(): void
    {
        // Six fully documented hotels would win every slot on completeness alone:
        // the per-family cap reserves one for the outdoor family, so the stage never
        // returns hotels only (#869).
        $candidates = [];
        foreach (range(1, 6) as $i) {
            $candidates[] = $this->candidate(
                \sprintf('Hotel %d', $i),
                'hotel',
                priceMin: 60.0 + $i,
                url: 'https://hotel.example',
                hasWebsite: true,
                description: 'Chambres confortables',
                stars: 4,
                capacity: 20,
            );
        }

        $candidates[] = $this->candidate('Camping Municipal', 'camp_site', priceMin: 12.0);

        $names = $this->publishedNames('trip-diversity', $candidates);

        self::assertContains('Camping Municipal', $names);
        self::assertCount(4, array_filter($names, static fn (string $name): bool => str_starts_with($name, 'Hotel ')));
    }

    #[Test]
    public function rankingIsReproducibleAcrossRunsAndStableOnTies(): void
    {
        // Same completeness, same price: the retained order must be the (deterministic,
        // nearest-first) order the repositories produced, on every run (#868/#869).
        $candidates = [];
        foreach (range(1, 6) as $i) {
            $candidates[] = $this->candidate(\sprintf('Gîte %d', $i), 'guest_house', priceMin: 40.0);
        }

        $first = $this->publishedNames('trip-reproducible', $candidates);
        $second = $this->publishedNames('trip-reproducible', $candidates);

        self::assertSame($first, $second);
        self::assertSame(['Gîte 1', 'Gîte 2', 'Gîte 3', 'Gîte 4', 'Gîte 5'], $first);
    }

    #[Test]
    public function aRestDayGetsTheSameRankedCandidatesAsTheNightBefore(): void
    {
        // Real distributor and real ranker: a rest day shares the previous stage's
        // end point, so both nights must see the same ranked selection, diversity
        // guard included — not an empty list on the second night (#869).
        $sharedEnd = new Coordinate(48.5, 2.5);
        $stage = new Stage('trip-rest', 1, 80.0, 500.0, new Coordinate(48.0, 2.0), $sharedEnd);
        $restDay = new Stage('trip-rest', 2, 0.0, 0.0, $sharedEnd, $sharedEnd, geometry: [$sharedEnd], isRestDay: true);

        $candidates = [];
        foreach (range(1, 6) as $i) {
            $candidates[] = $this->candidate(
                \sprintf('Hotel %d', $i),
                'hotel',
                priceMin: 60.0 + $i,
                url: 'https://hotel.example',
                hasWebsite: true,
                description: 'Chambres confortables',
            );
        }

        $candidates[] = $this->candidate('Camping Municipal', 'camp_site', priceMin: 12.0);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage, $restDay]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn($candidates);

        $published = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            static function (string $id, MercureEventType $type, array $payload) use (&$published): void {
                /** @var list<array{name: string}> $accommodations */
                $accommodations = $payload['accommodations'];
                /** @var int $stageIndex */
                $stageIndex = $payload['stageIndex'];
                $published[$stageIndex] = array_column($accommodations, 'name');
            },
        );

        $haversine = new HaversineDistance();
        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $registry,
            $haversine,
            new GeometryBasedDistributor($haversine),
        );
        $handler(new ScanAccommodations('trip-rest'));

        self::assertArrayHasKey(1, $published);
        self::assertSame($published[0], $published[1], 'both nights are spent at the same place');
        self::assertCount(5, $published[1]);
        self::assertContains('Camping Municipal', $published[1]);
        self::assertCount(4, array_filter($published[1], static fn (string $name): bool => str_starts_with($name, 'Hotel ')));
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(
        string $name,
        string $type,
        float $priceMin,
        ?string $url = null,
        bool $hasWebsite = false,
        ?string $description = null,
        ?string $openingHours = null,
        ?int $stars = null,
        ?int $capacity = null,
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'lat' => 48.6,
            'lon' => 2.6,
            'priceMin' => $priceMin,
            'priceMax' => $priceMin + 10.0,
            'isExact' => false,
            'url' => $url,
            'stars' => $stars,
            'capacity' => $capacity,
            'fee' => null,
            'tagCount' => 2,
            'hasWebsite' => $hasWebsite,
            'tags' => [],
            'source' => 'osm',
            'wikidataId' => null,
            'description' => $description,
            'openingHours' => $openingHours,
        ];
    }

    /**
     * Runs a full scan over the given candidates and returns the retained names, in
     * the order the handler published them.
     *
     * @param list<array<string, mixed>> $candidates
     *
     * @return list<string>
     */
    private function publishedNames(string $tripId, array $candidates): array
    {
        $stage = $this->createStage($tripId, 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $registry = $this->createStub(AccommodationSourceRegistry::class);
        $registry->method('fetchAll')->willReturn([]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([0 => $candidates]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(1.0);

        $published = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            static function (string $id, MercureEventType $type, array $payload) use (&$published): void {
                /** @var list<array{name: string}> $accommodations */
                $accommodations = $payload['accommodations'];
                $published = array_column($accommodations, 'name');
            },
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new ScanAccommodations($tripId));

        return $published;
    }
}
