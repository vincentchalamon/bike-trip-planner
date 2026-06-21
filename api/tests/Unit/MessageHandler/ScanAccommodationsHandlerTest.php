<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Accommodation\SeasonalityCheckerInterface;
use App\AccommodationSource\AccommodationSourceRegistry;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAccommodations;
use App\MessageHandler\ScanAccommodationsHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
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

        $messageBus = $this->createStub(MessageBusInterface::class);

        $handler = new ScanAccommodationsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $registry,
            $haversine,
            $distributor,
            $seasonalityChecker,
            $translator,
            $messageBus,
        );
        $handler->setCompletionGate(new TripCompletionGate($computationTracker, $publisher, $messageBus));

        return $handler;
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
}
