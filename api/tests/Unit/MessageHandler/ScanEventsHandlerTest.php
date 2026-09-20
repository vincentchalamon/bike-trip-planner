<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Tests\Unit\AlertMessageTestTrait;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\EventSource\EventSourceInterface;
use App\EventSource\EventSourceRegistry;
use App\Geo\HaversineDistance;
use App\Geo\NearbyNameDeduplicator;
use App\Mapper\EventArrayMapper;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanEvents;
use App\MessageHandler\ScanEventsHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class ScanEventsHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

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
        EventSourceInterface $eventSource,
    ): ScanEventsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new ScanEventsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            new EventSourceRegistry([$eventSource], new NearbyNameDeduplicator(new HaversineDistance()), new HaversineDistance()),
            new EventArrayMapper(),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
    }

    private function createTripRequest(\DateTimeImmutable $startDate): TripRequest
    {
        $request = new TripRequest();
        $request->startDate = $startDate;

        return $request;
    }

    /**
     * @return array{name: string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: string, description: ?string, priceMin: ?float, source: string}
     */
    private function eventRow(string $name, string $category, string $startDate, string $endDate, string $url = 'https://event.example.com'): array
    {
        return [
            'name' => $name,
            'category' => $category,
            'lat' => 48.5,
            'lon' => 2.5,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'url' => $url,
            'description' => null,
            'priceMin' => null,
            'source' => 'datatourisme',
        ];
    }

    #[Test]
    public function nullStagesSkipsPublish(): void
    {
        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $handler = $this->createHandler($tripStateManager, $publisher, $this->createStub(EventSourceInterface::class));
        $handler(new ScanEvents('trip-1'));
    }

    #[Test]
    public function noStartDateSkipsPublish(): void
    {
        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$this->createStage(1)]);
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());

        $handler = $this->createHandler($tripStateManager, $publisher, $this->createStub(EventSourceInterface::class));
        $handler(new ScanEvents('trip-1'));
    }

    /**
     * A rest day is never scanned, but it is still written — as empty.
     *
     * Since ADR-068 events survive the next edit, so a stage that has become a rest day
     * would otherwise keep the events a previous run left on it, for good.
     */
    #[Test]
    public function aRestDayIsNotScannedButIsStillCleared(): void
    {
        $eventSource = $this->createMock(EventSourceInterface::class);
        $eventSource->expects($this->never())->method('findActiveNear');

        $stage = $this->createStage(1, true);
        $written = [];
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest(new \DateTimeImmutable('2026-07-01')));
        $tripStateManager->method('updateStageEvents')->willReturnCallback(
            static function (string $tripId, string $stageId, array $events) use (&$written): void {
                $written[$stageId] = $events;
            },
        );

        $handler = $this->createHandler($tripStateManager, $this->createStub(TripUpdatePublisherInterface::class), $eventSource);
        $handler(new ScanEvents('trip-1'));

        self::assertSame([$stage->id => []], $written);
    }

    #[Test]
    public function publishesEventsActiveOnEachStageDate(): void
    {
        $startDate = new \DateTimeImmutable('2026-07-10');
        $stages = [$this->createStage(1), $this->createStage(2), $this->createStage(3)];

        $eventSource = $this->createStub(EventSourceInterface::class);
        // Stage 0 → 2026-07-10, stage 1 → 2026-07-11, stage 2 → 2026-07-12.
        $eventSource->method('findActiveNear')->willReturnCallback(
            fn (float $lat, float $lon, int $radius, string $date): array => match ($date) {
                '2026-07-10' => [$this->eventRow('Festival de Jazz', 'festival', '2026-07-10', '2026-07-14', 'https://festival.example.com')],
                '2026-07-11' => [$this->eventRow('Expo Renoir', 'exhibition', '2026-07-11', '2026-07-30')],
                default => [],
            },
        );

        $published = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            static function (string $tripId, MercureEventType $type, array $payload) use (&$published): void {
                $published[] = ['type' => $type, 'payload' => $payload];
            },
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest($startDate));

        $handler = $this->createHandler($tripStateManager, $publisher, $eventSource);
        $handler(new ScanEvents('trip-1'));

        $events = array_values(array_filter($published, static fn (array $e): bool => MercureEventType::EVENTS_FOUND === $e['type']));

        // One per stage, the empty third included: an empty result is a result, and the
        // client replaces the stage's events wholesale from this payload.
        self::assertCount(3, $events);
        self::assertSame($stages[0]->id, $events[0]['payload']['stageId']);
        self::assertSame('Festival de Jazz', $events[0]['payload']['events'][0]['name']);
        self::assertSame('festival', $events[0]['payload']['events'][0]['type']);
        self::assertSame('https://festival.example.com', $events[0]['payload']['events'][0]['url']);
        self::assertSame('datatourisme', $events[0]['payload']['events'][0]['source']);
        self::assertSame($stages[1]->id, $events[1]['payload']['stageId']);
        self::assertSame('Expo Renoir', $events[1]['payload']['events'][0]['name']);
        self::assertSame([], $events[2]['payload']['events']);
    }

    /**
     * The reason lot B exists, for the group it forgot: events were published over SSE and
     * written nowhere, so a reload and the anonymous share page lost them entirely.
     *
     * The same array goes to the database and to Mercure, which is what makes GET/SSE parity
     * hold by construction rather than by vigilance (ADR-068).
     */
    #[Test]
    public function eventsArePersistedWithTheSameContentTheyArePublishedWith(): void
    {
        $stages = [$this->createStage(1), $this->createStage(2)];

        $eventSource = $this->createStub(EventSourceInterface::class);
        $eventSource->method('findActiveNear')->willReturnCallback(
            fn (float $lat, float $lon, int $radius, string $date): array => '2026-07-10' === $date
                ? [$this->eventRow('Festival de Jazz', 'festival', '2026-07-10', '2026-07-14')]
                : [],
        );

        $written = [];
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest(new \DateTimeImmutable('2026-07-10')));
        $tripStateManager->method('updateStageEvents')->willReturnCallback(
            static function (string $tripId, string $stageId, array $events) use (&$written): void {
                $written[$stageId] = $events;
            },
        );

        $published = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            static function (string $tripId, MercureEventType $type, array $payload) use (&$published): void {
                $published[$payload['stageId']] = $payload['events'];
            },
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $eventSource);
        $handler(new ScanEvents('trip-1'));

        self::assertCount(1, $written[$stages[0]->id]);
        self::assertSame('Festival de Jazz', $written[$stages[0]->id][0]->name);
        // The second stage found nothing, and that clears rather than leaves standing.
        self::assertSame([], $written[$stages[1]->id]);

        $mapper = new EventArrayMapper();
        self::assertSame(
            array_map($mapper->toArray(...), $written[$stages[0]->id]),
            $published[$stages[0]->id],
        );
    }
}
