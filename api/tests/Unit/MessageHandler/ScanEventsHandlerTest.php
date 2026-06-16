<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\Market;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanEvents;
use App\MessageHandler\ScanEventsHandler;
use App\Repository\MarketRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Tourism\EventRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        EventRepositoryInterface $eventRepository,
        GeoDistanceInterface $haversine,
        ?MarketRepositoryInterface $marketRepository = null,
        ?TranslatorInterface $translator = null,
    ): ScanEventsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $marketRepository ??= $this->createStub(MarketRepositoryInterface::class);
        $translator ??= $this->createStub(TranslatorInterface::class);

        return new ScanEventsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $eventRepository,
            $haversine,
            $marketRepository,
            $translator,
            $this->createStub(MessageBusInterface::class),
        );
    }

    private function createTripRequest(\DateTimeImmutable $startDate): TripRequest
    {
        $request = new TripRequest();
        $request->startDate = $startDate;

        return $request;
    }

    /**
     * @return array{name: string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: ?string, description: ?string, priceMin: ?float}
     */
    private function eventRow(string $name, string $category, string $startDate, string $endDate, ?string $url = null, ?string $description = null): array
    {
        return [
            'name' => $name,
            'category' => $category,
            'lat' => 48.5,
            'lon' => 2.5,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'url' => $url,
            'description' => $description,
            'priceMin' => null,
        ];
    }

    #[Test]
    public function nullStagesSkipsPublish(): void
    {
        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $handler = $this->createHandler($tripStateManager, $publisher, $this->createStub(EventRepositoryInterface::class), $this->createStub(GeoDistanceInterface::class));
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

        $handler = $this->createHandler($tripStateManager, $publisher, $this->createStub(EventRepositoryInterface::class), $this->createStub(GeoDistanceInterface::class));
        $handler(new ScanEvents('trip-1'));
    }

    #[Test]
    public function restDayStageIsSkipped(): void
    {
        $eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventRepository->expects($this->never())->method('findActiveNear');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$this->createStage(1, true)]);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest(new \DateTimeImmutable('2026-07-01')));

        $handler = $this->createHandler($tripStateManager, $publisher, $eventRepository, $this->createStub(GeoDistanceInterface::class));
        $handler(new ScanEvents('trip-1'));
    }

    #[Test]
    public function publishesEventsActiveOnEachStageDate(): void
    {
        $startDate = new \DateTimeImmutable('2026-07-10');
        $stages = [$this->createStage(1), $this->createStage(2), $this->createStage(3)];

        $eventRepository = $this->createStub(EventRepositoryInterface::class);
        // Stage 0 → 2026-07-10, stage 1 → 2026-07-11, stage 2 → 2026-07-12.
        $eventRepository->method('findActiveNear')->willReturnCallback(
            fn (float $lat, float $lon, int $radius, string $date): array => match ($date) {
                '2026-07-10' => [$this->eventRow('Festival de Jazz', 'festival', '2026-07-10', '2026-07-14', 'https://festival.example.com', 'Grand festival')],
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

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(500.0);

        $handler = $this->createHandler($tripStateManager, $publisher, $eventRepository, $haversine);
        $handler(new ScanEvents('trip-1'));

        $events = array_values(array_filter($published, static fn (array $e): bool => MercureEventType::EVENTS_FOUND === $e['type']));

        // Only the two stages with active events publish; the empty third does not.
        self::assertCount(2, $events);
        self::assertSame(0, $events[0]['payload']['stageIndex']);
        self::assertSame('Festival de Jazz', $events[0]['payload']['events'][0]['name']);
        self::assertSame('festival', $events[0]['payload']['events'][0]['type']);
        self::assertSame('https://festival.example.com', $events[0]['payload']['events'][0]['url']);
        self::assertSame('datatourisme', $events[0]['payload']['events'][0]['source']);
        self::assertSame(1, $events[1]['payload']['stageIndex']);
        self::assertSame('Expo Renoir', $events[1]['payload']['events'][0]['name']);
    }

    #[Test]
    public function mergesDataTourismeAndMarketEventsForSameStage(): void
    {
        // 2026-07-13 is a Monday (ISO day 1).
        $startDate = new \DateTimeImmutable('2026-07-13');

        $eventRepository = $this->createStub(EventRepositoryInterface::class);
        $eventRepository->method('findActiveNear')->willReturn([
            $this->eventRow('Festival Jazz', 'festival', '2026-07-13', '2026-07-18'),
            $this->eventRow('Expo Impressionnisme', 'exhibition', '2026-07-12', '2026-07-20'),
        ]);

        $market = new Market('MKT-MON-001', 'Marché du Lundi');
        $market->setLat(48.49);
        $market->setLon(2.49);
        $market->setDayOfWeek(1);
        $market->setStartTime('07:00');
        $market->setEndTime('13:00');
        $market->setCommune('Paris');
        $market->setDepartment('75');

        $marketRepository = $this->createStub(MarketRepositoryInterface::class);
        $marketRepository->method('findNearEndpoint')->willReturn([$market]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Weekly market');

        $published = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            static function (string $tripId, MercureEventType $type, array $payload) use (&$published): void {
                $published[] = ['type' => $type, 'payload' => $payload];
            },
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$this->createStage(1)]);
        $tripStateManager->method('getRequest')->willReturn($this->createTripRequest($startDate));

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(400.0);

        $handler = $this->createHandler($tripStateManager, $publisher, $eventRepository, $haversine, $marketRepository, $translator);
        $handler(new ScanEvents('trip-1'));

        $events = array_values(array_filter($published, static fn (array $e): bool => MercureEventType::EVENTS_FOUND === $e['type']));
        self::assertCount(1, $events);
        self::assertCount(3, $events[0]['payload']['events']);

        $sources = array_column($events[0]['payload']['events'], 'source');
        self::assertContains('datatourisme', $sources);
        self::assertContains('data_gouv_markets', $sources);

        $marketEvents = array_values(array_filter($events[0]['payload']['events'], static fn (array $e): bool => 'data_gouv_markets' === $e['source']));
        self::assertSame('Marché du Lundi', $marketEvents[0]['name']);
        self::assertSame('market', $marketEvents[0]['type']);
    }
}
