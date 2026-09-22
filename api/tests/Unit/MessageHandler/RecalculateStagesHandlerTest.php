<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Service\EnrichmentMessageFactory;
use App\Service\TripAnalysisDispatcher;
use App\Service\TripCompletionGate;
use App\Tests\Unit\AlertMessageTestTrait;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationSupersession;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Enum\ComputationTrigger;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\ScanEvents;
use App\Message\ResolveStageLabels;
use App\Message\CheckFerries;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCulturalPois;
use App\Message\CheckRailwayStations;
use App\Message\CheckHealthServices;
use App\Message\CheckWaterPoints;
use App\Message\ScanPois;
use App\Message\AnalyzeTerrain;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\MessageHandler\RecalculateStagesHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecalculateStagesHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        MessageBusInterface $messageBus,
        ?TripGenerationTrackerInterface $generationTracker = null,
    ): RecalculateStagesHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'settled' => 0, 'total' => 1]);

        return new RecalculateStagesHandler(
            $computationTracker,
            $publisher,
            $generationTracker ?? $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            $messageBus,
            $this->createAlertRenderer(),
            new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory()),
            // A real one, inert: it is `final readonly` so it cannot be doubled, and with a
            // tracker that knows no statuses it settles nothing. What it does is covered by
            // ComputationSupersessionTest.
            new ComputationSupersession(
                $computationTracker,
                $publisher,
                new TripCompletionGate($computationTracker, $publisher, $messageBus, $this->createStub(TripGenerationTrackerInterface::class)),
                new NullLogger(),
            ),
        );
    }

    #[Test]
    public function publishesPerStageUpdatedWithGeometryAndStoredDistance(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        // A stage whose distance was set by the user (e.g. 80km -> 60km via
        // StageUpdateProcessor::applyDistanceChange). The handler must surface
        // this stored value verbatim through the per-stage `stage_updated` event.
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 60.0,
            elevation: 500.0,
            startPoint: $coordinate,
            endPoint: new Coordinate(49.0, 2.5, 50.0),
            geometry: [$coordinate],
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publishStageUpdated')
            ->with(
                'trip-1',
                $this->callback(static fn (Stage $s): bool => 60.0 === $s->distance
                    && 1 === \count($s->geometry)
                    && 48.8566 === $s->geometry[0]->lat),
            );
        // The legacy wholesale STAGES_COMPUTED must NOT be re-published on the
        // inline recompute path: it raced the per-stage update and reverted the
        // user-requested distance (issue #774).
        $publisher->expects($this->never())->method('publish');

        $handler = $this->createHandler($tripStateManager, $publisher, $messageBus);

        $handler(new RecalculateStages(tripId: 'trip-1', affectedStageIds: [], triggers: []));
    }

    #[Test]
    public function noStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->createStub(MessageBusInterface::class),
        );

        $handler(new RecalculateStages(tripId: 'trip-1', affectedStageIds: []));
    }

    /**
     * The gap ADR-070 closes: this handler used to name five computations out of twelve, so a
     * stage merge — which concatenates two geometries — left seven groups holding alerts
     * drawn from the line that no longer existed.
     */
    #[Test]
    public function aGeometryChangeRedispatchesEveryComputationDrawnFromTheLine(): void
    {
        $makeStage = static fn (int $day): Stage => new Stage(
            tripId: 'trip-1',
            dayNumber: $day,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
        );

        $stages = [$makeStage(1), $makeStage(2)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('+1 month');
        $tripStateManager->method('getRequest')->willReturn($request);

        /** @var list<object> $dispatched */
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            }
        );

        $handler = $this->createHandler(
            $tripStateManager,
            $this->createStub(TripUpdatePublisherInterface::class),
            $messageBus,
        );

        $handler(new RecalculateStages(tripId: 'trip-1', affectedStageIds: [$stages[0]->id]));

        $classes = array_map(static fn (object $m): string => $m::class, $dispatched);

        foreach ([
            CheckWaterPoints::class,
            CheckHealthServices::class,
            CheckRailwayStations::class,
            CheckCulturalPois::class,
            CheckBorderCrossing::class,
            CheckFerries::class,
            ResolveStageLabels::class,
        ] as $missedBefore) {
            $this->assertContains($missedBefore, $classes);
        }

        // Still dispatched, and the reason a naive split into two disjoint sets would have
        // been wrong: events sit near the stage end point as well as on its date.
        $this->assertContains(ScanEvents::class, $classes);

        // Holidays fall on a date the line cannot move.
        $this->assertNotContains(CheckCalendar::class, $classes);
    }

    /**
     * The overlap that a second dispatch mechanism used to create.
     *
     * Five computations sit in both sets — POIS, ACCOMMODATIONS, TERRAIN, EVENTS and WEATHER
     * all read the line *and* a date. When the geometry set was dispatched here and the date
     * set by the sender, a merge re-ran those five twice. Both now travel on one message, so
     * the union goes out once (ADR-070).
     */
    #[Test]
    public function anEditInvalidatingBothTheLineAndTheDatesDispatchesTheUnionOnce(): void
    {
        $makeStage = static fn (int $day): Stage => new Stage(
            tripId: 'trip-1',
            dayNumber: $day,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
        );

        $stages = [$makeStage(1), $makeStage(2)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('+1 month');
        $tripStateManager->method('getRequest')->willReturn($request);

        /** @var list<object> $dispatched */
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            }
        );

        $handler = $this->createHandler(
            $tripStateManager,
            $this->createStub(TripUpdatePublisherInterface::class),
            $messageBus,
        );

        $handler(new RecalculateStages(
            tripId: 'trip-1',
            affectedStageIds: [$stages[0]->id],
            triggers: [ComputationTrigger::GEOMETRY, ComputationTrigger::DATES],
        ));

        $classes = array_map(static fn (object $m): string => $m::class, $dispatched);
        $counts = array_count_values($classes);

        foreach ([ScanPois::class, AnalyzeTerrain::class, ScanEvents::class, FetchWeather::class] as $inBothSets) {
            $this->assertSame(1, $counts[$inBothSets] ?? 0, \sprintf('%s must be dispatched exactly once.', $inBothSets));
        }

        // The date half brings in the one computation the line alone never would.
        $this->assertContains(CheckCalendar::class, $classes);
    }

    #[Test]
    public function stagesComputedDispatchesScanAccommodationsPerAffectedStage(): void
    {
        $makeStage = static fn (int $day): Stage => new Stage(
            tripId: 'trip-1',
            dayNumber: $day,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
        );

        $stages = [$makeStage(1), $makeStage(2), $makeStage(3)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);

        $request = new TripRequest();
        $tripStateManager->method('getRequest')->willReturn($request);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        /** @var list<object> $dispatched */
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            }
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $messageBus);

        $handler(new RecalculateStages(tripId: 'trip-1', affectedStageIds: [$stages[0]->id, $stages[2]->id]));

        $scanMessages = array_values(array_filter(
            $dispatched,
            static fn (object $m): bool => $m instanceof ScanAccommodations,
        ));

        $this->assertCount(2, $scanMessages);

        /** @var ScanAccommodations $first */
        $first = $scanMessages[0];
        $this->assertSame($stages[0]->id, $first->stageId);
        $this->assertFalse($first->isExpandScan);

        /** @var ScanAccommodations $second */
        $second = $scanMessages[1];
        $this->assertSame($stages[2]->id, $second->stageId);
        $this->assertFalse($second->isExpandScan);
    }
}
