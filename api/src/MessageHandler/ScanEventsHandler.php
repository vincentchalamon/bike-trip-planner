<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Event;
use App\ApiResource\Stage;
use App\EventSource\EventSourceRegistry;
use App\Mapper\EventArrayMapper;
use App\Mercure\MercureEventType;
use App\Message\ScanEvents;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
        TripHandlerContext $context,
        private EventSourceRegistry $eventSources,
        private EventArrayMapper $eventMapper,
    ) {
        parent::__construct($context);
    }

    public function __invoke(ScanEvents $message): void
    {
        $tripId = $message->tripId;

        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            $this->executeWithTracking($message, static fn (): null => null);

            return;
        }

        $request = $this->tripRequestRepository->getRequest($tripId);
        $startDate = $request?->startDate;

        if (!$startDate instanceof \DateTimeImmutable) {
            $this->executeWithTracking($message, static fn (): null => null);

            return;
        }

        $this->executeWithTracking($message, function () use ($tripId, $stages, $startDate): void {
            foreach ($stages as $stage) {
                // A rest day is not scanned, and carries no events: the empty write below
                // clears whatever a previous run left on a stage that has since become one.
                $events = $stage->isRestDay
                    ? []
                    : $this->fetchEventsForStage($stage, $stage->dateFrom($startDate));

                foreach ($events as $event) {
                    $stage->addEvent($event);
                }

                // Written and published unconditionally, the empty list included (ADR-068).
                // Events were the last enrichment delivered over SSE and persisted nowhere,
                // so the anonymous share page and a reload lost them entirely.
                $this->stageStore->updateStageEvents($tripId, $stage->id, $events);
                $this->publisher->publish($tripId, MercureEventType::EVENTS_FOUND, [
                    'stageId' => $stage->id,
                    'events' => array_map($this->eventMapper->toArray(...), $events),
                ]);
            }
        });
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
