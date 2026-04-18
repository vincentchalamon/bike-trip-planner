<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\DataTourisme\DataTourismeClientInterface;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanEvents;
use App\MessageHandler\ScanEventsHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ScanEventsHandlerTest extends TestCase
{
    private function createStage(int $dayNumber, bool $isRestDay = false): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: $dayNumber,
            distance: $isRestDay ? 0.0 : 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(lat: 48.0, lon: 2.0),
            endPoint: new Coordinate(lat: 48.5, lon: 2.5),
            isRestDay: $isRestDay,
        );
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        DataTourismeClientInterface $dataTourismeClient,
        GeoDistanceInterface $haversine,
    ): ScanEventsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new ScanEventsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $dataTourismeClient,
            $haversine,
        );
    }

    private function createTripRequest(\DateTimeImmutable $startDate): TripRequest
    {
        $request = new TripRequest();
        $request->startDate = $startDate;

        return $request;
    }

    #[Test]
    public function disabledClientSkipsPublish(): void
    {
        $dataTourismeClient = $this->createStub(DataTourismeClientInterface::class);
        $dataTourismeClient->method('isEnabled')->willReturn(false);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $dataTourismeClient, $haversine);
        $handler(new ScanEvents('trip-1'));
    }

    #[Test]
    public function nullStagesSkipsPublish(): void
    {
        $dataTourismeClient = $this->createStub(DataTourismeClientInterface::class);
        $dataTourismeClient->method('isEnabled')->willReturn(true);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $dataTourismeClient, $haversine);
        $handler(new ScanEvents('trip-1'));
    }

    #[Test]
    public function noStartDateSkipsPublish(): void
    {
        $dataTourismeClient = $this->createStub(DataTourismeClientInterface::class);
        $dataTourismeClient->method('isEnabled')->willReturn(true);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $stage = $this->createStage(1);
        $request = new TripRequest();
        // startDate is null

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getRequest')->willReturn($request);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $dataTourismeClient, $haversine);
        $handler(new ScanEvents('trip-1'));
    }

    #[Test]
    public function restDayStageIsSkipped(): void
    {
        $startDate = new \DateTimeImmutable('2025-07-01');
        $restDay = $this->createStage(1, true);

        $dataTourismeClient = $this->createMock(DataTourismeClientInterface::class);
        $dataTourismeClient->method('isEnabled')->willReturn(true);
        $dataTourismeClient->expects($this->never())->method('request');

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$restDay]);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest($startDate));

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $dataTourismeClient, $haversine);
        $handler(new ScanEvents('trip-1'));

        self::assertCount(0, $publishedEvents);
    }

    #[Test]
    public function threeStagesWithTemporalFilterPublishesEventsFound(): void
    {
        $startDate = new \DateTimeImmutable('2025-07-10');
        $stage0 = $this->createStage(1);
        $stage1 = $this->createStage(2);
        $stage2 = $this->createStage(3);

        $festivalResult = [
            '@type' => ['schema:Festival'],
            'rdfs:label' => 'Festival de Jazz',
            'hasGeometry' => ['latitude' => 48.5, 'longitude' => 2.5],
            'startDate' => '2025-07-10',
            'endDate' => '2025-07-14',
            'foaf:homepage' => 'https://festival.example.com',
            'shortDescription' => 'Grand festival annuel',
        ];

        $exhibitionResult = [
            '@type' => ['schema:Exhibition'],
            'rdfs:label' => 'Expo Renoir',
            'hasGeometry' => ['latitude' => 48.51, 'longitude' => 2.51],
            'startDate' => '2025-07-11',
            'endDate' => '2025-07-30',
        ];

        $dataTourismeClient = $this->createStub(DataTourismeClientInterface::class);
        $dataTourismeClient->method('isEnabled')->willReturn(true);
        $dataTourismeClient->method('request')->willReturnCallback(
            static function (string $path, array $query) use ($festivalResult, $exhibitionResult): array {
                // stage 0: 2025-07-10 → festival is ongoing
                if ('2025-07-10' === ($query['startDate[before]'] ?? null)) {
                    return ['results' => [$festivalResult]];
                }

                // stage 1: 2025-07-11 → exhibition starts
                if ('2025-07-11' === ($query['startDate[before]'] ?? null)) {
                    return ['results' => [$exhibitionResult]];
                }

                return ['results' => []];
            },
        );

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage0, $stage1, $stage2]);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest($startDate));

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(500.0);

        $handler = $this->createHandler($tripStateManager, $publisher, $dataTourismeClient, $haversine);
        $handler(new ScanEvents('trip-1'));

        $eventsPublished = array_filter(
            $publishedEvents,
            static fn (array $e): bool => MercureEventType::EVENTS_FOUND === $e['type'],
        );

        // stage 0 and stage 1 publish events; stage 2 publishes empty
        self::assertCount(3, $eventsPublished);

        $eventsPublished = array_values($eventsPublished);

        // stage 0
        self::assertSame(0, $eventsPublished[0]['payload']['stageIndex']);
        self::assertCount(1, $eventsPublished[0]['payload']['events']);
        self::assertSame('Festival de Jazz', $eventsPublished[0]['payload']['events'][0]['name']);
        self::assertSame('schema:Festival', $eventsPublished[0]['payload']['events'][0]['type']);
        self::assertSame('https://festival.example.com', $eventsPublished[0]['payload']['events'][0]['url']);
        self::assertSame('Grand festival annuel', $eventsPublished[0]['payload']['events'][0]['description']);
        self::assertSame('datatourisme', $eventsPublished[0]['payload']['events'][0]['source']);

        // stage 1
        self::assertSame(1, $eventsPublished[1]['payload']['stageIndex']);
        self::assertCount(1, $eventsPublished[1]['payload']['events']);
        self::assertSame('Expo Renoir', $eventsPublished[1]['payload']['events'][0]['name']);

        // stage 2 → empty
        self::assertSame(2, $eventsPublished[2]['payload']['stageIndex']);
        self::assertCount(0, $eventsPublished[2]['payload']['events']);
    }

    #[Test]
    public function unknownTypeIsFiltered(): void
    {
        $startDate = new \DateTimeImmutable('2025-08-01');
        $stage = $this->createStage(1);

        $unknownResult = [
            '@type' => ['schema:SportsEvent'],
            'rdfs:label' => 'Triathlon',
            'hasGeometry' => ['latitude' => 48.5, 'longitude' => 2.5],
            'startDate' => '2025-08-01',
            'endDate' => '2025-08-02',
        ];

        $dataTourismeClient = $this->createStub(DataTourismeClientInterface::class);
        $dataTourismeClient->method('isEnabled')->willReturn(true);
        $dataTourismeClient->method('request')->willReturn(['results' => [$unknownResult]]);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest($startDate));

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $dataTourismeClient, $haversine);
        $handler(new ScanEvents('trip-1'));

        $eventsPublished = array_filter(
            $publishedEvents,
            static fn (array $e): bool => MercureEventType::EVENTS_FOUND === $e['type'],
        );

        $event = array_values($eventsPublished)[0] ?? null;
        self::assertNotNull($event);
        self::assertCount(0, $event['payload']['events']);
    }

    #[Test]
    public function wikidataIdIsExtracted(): void
    {
        $startDate = new \DateTimeImmutable('2025-09-01');
        $stage = $this->createStage(1);

        $result = [
            '@type' => ['schema:MusicEvent'],
            'rdfs:label' => 'Concert en plein air',
            'hasGeometry' => ['latitude' => 48.5, 'longitude' => 2.5],
            'startDate' => '2025-09-01',
            'endDate' => '2025-09-01',
            'owl:sameAs' => ['https://www.wikidata.org/entity/Q12345', 'https://dbpedia.org/page/Concert'],
        ];

        $dataTourismeClient = $this->createStub(DataTourismeClientInterface::class);
        $dataTourismeClient->method('isEnabled')->willReturn(true);
        $dataTourismeClient->method('request')->willReturn(['results' => [$result]]);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest($startDate));

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(300.0);

        $handler = $this->createHandler($tripStateManager, $publisher, $dataTourismeClient, $haversine);
        $handler(new ScanEvents('trip-1'));

        $eventsPublished = array_values(array_filter(
            $publishedEvents,
            static fn (array $e): bool => MercureEventType::EVENTS_FOUND === $e['type'],
        ));

        self::assertCount(1, $eventsPublished);
        self::assertSame('Q12345', $eventsPublished[0]['payload']['events'][0]['wikidataId']);
    }
}
