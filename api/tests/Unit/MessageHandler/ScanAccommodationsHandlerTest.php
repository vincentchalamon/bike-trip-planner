<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Accommodation\AccommodationMetadataExtractor;
use App\Accommodation\SeasonalityCheckerInterface;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
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
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
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
        ?HttpClientInterface $scraperClient = null,
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

        $scraperClient ??= $this->createStub(HttpClientInterface::class);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new ScanAccommodationsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
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

    #[Test]
    public function scraperClientUsesThreeSecondTimeoutForWaveOne(): void
    {
        $stage = $this->createStage('trip-timeout', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.6, 'lon' => 2.6, 'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Test', 'website' => 'https://example.com']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel Test', 'type' => 'hotel', 'lat' => 48.6, 'lon' => 2.6,
                'priceMin' => 50.0, 'priceMax' => 100.0, 'isExact' => false,
                'url' => 'https://example.com', 'tagCount' => 3, 'hasWebsite' => true,
                'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Test', 'website' => 'https://example.com']]],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(1.0);

        $scraperClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html></html>');

        $scraperClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com', ['timeout' => 3])
            ->willReturn($response);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor, $scraperClient);
        $handler(new ScanAccommodations('trip-timeout'));
    }

    #[Test]
    public function wave1TimeoutPreservesOsmDataAndDoesNotThrow(): void
    {
        $stage = $this->createStage('trip-fallback', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.6, 'lon' => 2.6, 'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Timeout', 'website' => 'https://slow-site.example.com']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel Timeout', 'type' => 'hotel', 'lat' => 48.6, 'lon' => 2.6,
                'priceMin' => 50.0, 'priceMax' => 100.0, 'isExact' => false,
                'url' => 'https://slow-site.example.com', 'tagCount' => 3, 'hasWebsite' => true,
                'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Timeout', 'website' => 'https://slow-site.example.com']]],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(2.5);

        // Simulate a timeout: request succeeds (non-blocking) but getContent() throws
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willThrowException(new TimeoutException('Idle timeout reached'));

        $scraperClient = $this->createStub(HttpClientInterface::class);
        $scraperClient->method('request')->willReturn($response);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-fallback',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $accommodations = $data['accommodations'];

                    // Accommodation must still be present with its original OSM data
                    return 1 === \count($accommodations)
                        && 'Hotel Timeout' === $accommodations[0]['name']
                        && 'hotel' === $accommodations[0]['type']
                        && 48.6 === $accommodations[0]['lat']
                        && 2.6 === $accommodations[0]['lon']
                        && 50.0 === $accommodations[0]['estimatedPriceMin']
                        && 100.0 === $accommodations[0]['estimatedPriceMax']
                        && false === $accommodations[0]['isExactPrice']
                        && 'https://slow-site.example.com' === $accommodations[0]['url'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor, $scraperClient);
        $handler(new ScanAccommodations('trip-fallback'));

        // Accommodation is still added to the stage despite scraping failure
        $this->assertCount(1, $stage->accommodations);
        $this->assertSame('Hotel Timeout', $stage->accommodations[0]->name);
        $this->assertFalse($stage->accommodations[0]->possibleClosed);
    }

    #[Test]
    public function scraperClientUsesTwoSecondTimeoutForWaveTwo(): void
    {
        $stage = $this->createStage('trip-timeout2', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.6, 'lon' => 2.6, 'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Test', 'website' => 'https://example.com']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel Test', 'type' => 'hotel', 'lat' => 48.6, 'lon' => 2.6,
                'priceMin' => 50.0, 'priceMax' => 100.0, 'isExact' => false,
                'url' => 'https://example.com', 'tagCount' => 3, 'hasWebsite' => true,
                'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Test', 'website' => 'https://example.com']]],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(1.0);

        // Wave 1: return HTML with no price but with a price-page link (triggers wave 2)
        $wave1Response = $this->createStub(ResponseInterface::class);
        $wave1Response->method('getContent')->willReturn('<html><body><a href="https://example.com/tarifs">Tarifs</a></body></html>');

        // Wave 2: return simple HTML
        $wave2Response = $this->createStub(ResponseInterface::class);
        $wave2Response->method('getContent')->willReturn('<html><body>65€ per night</body></html>');

        $scraperClient = $this->createMock(HttpClientInterface::class);
        $scraperClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(
                function (string $method, string $url, array $options) use ($wave1Response, $wave2Response): ResponseInterface {
                    if ('https://example.com' === $url) {
                        $this->assertSame(['timeout' => 3], $options);

                        return $wave1Response;
                    }

                    $this->assertSame('https://example.com/tarifs', $url);
                    $this->assertSame(['timeout' => 2], $options);

                    return $wave2Response;
                },
            );

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor, $scraperClient);
        $handler(new ScanAccommodations('trip-timeout2'));
    }

    #[Test]
    public function wave2TimeoutPreservesHeuristicPriceAndDoesNotThrow(): void
    {
        $stage = $this->createStage('trip-wave2', 48.5, 2.5);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.6, 'lon' => 2.6, 'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Wave2', 'website' => 'https://wave2.example.com']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByEndpoint')->willReturn([
            0 => [['name' => 'Hotel Wave2', 'type' => 'hotel', 'lat' => 48.6, 'lon' => 2.6,
                'priceMin' => 50.0, 'priceMax' => 100.0, 'isExact' => false,
                'url' => 'https://wave2.example.com', 'tagCount' => 3, 'hasWebsite' => true,
                'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Wave2', 'website' => 'https://wave2.example.com']]],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(1.5);

        // Wave 1: return HTML with no price (triggers wave 2)
        $wave1Response = $this->createStub(ResponseInterface::class);
        $wave1Response->method('getContent')->willReturn('<html><body><h1>Hotel Wave2</h1></body></html>');

        // Wave 2: timeout on price page
        $wave2Response = $this->createStub(ResponseInterface::class);
        $wave2Response->method('getContent')->willThrowException(new TimeoutException('Idle timeout reached'));

        $scraperClient = $this->createStub(HttpClientInterface::class);
        $callCount = 0;
        $scraperClient->method('request')->willReturnCallback(
            function () use (&$callCount, $wave1Response, $wave2Response): ResponseInterface {
                return 0 === $callCount++ ? $wave1Response : $wave2Response;
            },
        );

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-wave2',
                MercureEventType::ACCOMMODATIONS_FOUND,
                $this->callback(static function (array $data): bool {
                    $accommodations = $data['accommodations'];

                    // Accommodation retains heuristic price (wave 2 failed)
                    return 1 === \count($accommodations)
                        && 'Hotel Wave2' === $accommodations[0]['name']
                        && 50.0 === $accommodations[0]['estimatedPriceMin']
                        && 100.0 === $accommodations[0]['estimatedPriceMax']
                        && false === $accommodations[0]['isExactPrice'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor, $scraperClient);
        $handler(new ScanAccommodations('trip-wave2'));

        $this->assertCount(1, $stage->accommodations);
        $this->assertFalse($stage->accommodations[0]->isExactPrice);
    }
}
