<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Event;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\EventSource\EventSourceRegistry;
use App\Mapper\EventArrayMapper;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanEvents;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Attaches dated events to each stage: multi-source events (DataTourisme today,
 * OpenAgenda next — ADR-051) read from the local-first `tourism` schema (ADR-040,
 * no longer the runtime REST API), filtered to the stage's own date and a radius
 * around its end point, then deduplicated, relevance-filtered and distance-ranked
 * by {@see EventSourceRegistry}.
 */
#[AsMessageHandler]
final readonly class ScanEventsHandler extends AbstractTripMessageHandler
{
    private const int EVENT_RADIUS_METERS = 20_000;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private EventSourceRegistry $eventSources,
        private EventArrayMapper $eventMapper,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(ScanEvents $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;

        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            $this->executeWithTracking($tripId, ComputationName::EVENTS, static fn (): null => null, $generation);

            return;
        }

        $request = $this->tripStateManager->getRequest($tripId);
        $startDate = $request?->startDate;

        if (!$startDate instanceof \DateTimeImmutable) {
            $this->executeWithTracking($tripId, ComputationName::EVENTS, static fn (): null => null, $generation);

            return;
        }

        $this->executeWithTracking($tripId, ComputationName::EVENTS, function () use ($tripId, $stages, $startDate): void {
            foreach ($stages as $i => $stage) {
                // A rest day is not scanned, and carries no events: the empty write below
                // clears whatever a previous run left on a stage that has since become one.
                $events = $stage->isRestDay
                    ? []
                    : $this->fetchEventsForStage($stage, $startDate->modify(\sprintf('+%d days', $i)));

                foreach ($events as $event) {
                    $stage->addEvent($event);
                }

                // Written and published unconditionally, the empty list included (ADR-068).
                // Events were the last enrichment delivered over SSE and persisted nowhere,
                // so the anonymous share page and a reload lost them entirely.
                $this->tripStateManager->updateStageEvents($tripId, $stage->id, $events);
                $this->publisher->publish($tripId, MercureEventType::EVENTS_FOUND, [
                    'stageId' => $stage->id,
                    'events' => array_map($this->eventMapper->toArray(...), $events),
                ]);
            }
        }, $generation);
    }

    /**
     * @return list<Event>
     */
    private function fetchEventsForStage(Stage $stage, \DateTimeImmutable $stageDate): array
    {
        $events = [];

        foreach ($this->eventSources->findAllActiveNear(
            $stage->endPoint->lat,
            $stage->endPoint->lon,
            self::EVENT_RADIUS_METERS,
            $stageDate->format('Y-m-d'),
        ) as $row) {
            if (null === $row['name']) {
                continue;
            }

            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['startDate']);
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['endDate']);

            if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
                continue;
            }

            $events[] = new Event(
                name: $row['name'],
                type: $row['category'],
                lat: $row['lat'],
                lon: $row['lon'],
                startDate: $start,
                endDate: $end,
                url: $row['url'],
                description: $row['description'],
                priceMin: $row['priceMin'],
                distanceToEndPoint: $row['distanceToEndPoint'],
                source: $row['source'],
            );
        }

        return $events;
    }
}
