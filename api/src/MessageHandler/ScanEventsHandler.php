<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Event;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanEvents;
use App\Repository\TripRequestRepositoryInterface;
use App\Tourism\EventRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Attaches dated events to each stage: DataTourisme events read from the
 * local-first `tourism` schema (ADR-040, no longer the runtime REST API),
 * filtered to the stage's own date and a radius around its end point.
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
        private EventRepositoryInterface $eventRepository,
        private GeoDistanceInterface $haversine,
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
                if ($stage->isRestDay) {
                    continue;
                }

                $stageDate = $startDate->modify(\sprintf('+%d days', $i));
                $events = $this->fetchEventsForStage($stage, $stageDate);

                if ([] === $events) {
                    continue;
                }

                usort($events, static fn (Event $a, Event $b): int => $a->startDate <=> $b->startDate);

                foreach ($events as $event) {
                    $stage->addEvent($event);
                }

                $this->publisher->publish($tripId, MercureEventType::EVENTS_FOUND, [
                    'stageIndex' => $i,
                    'events' => array_map($this->eventToArray(...), $events),
                ]);

                $stages[$i] = $stage;
            }

            $this->tripStateManager->storeStages($tripId, array_values($stages));
        }, $generation);
    }

    /**
     * @return list<Event>
     */
    private function fetchEventsForStage(Stage $stage, \DateTimeImmutable $stageDate): array
    {
        $events = [];

        foreach ($this->eventRepository->findActiveNear(
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
                distanceToEndPoint: $this->haversine->inMeters($row['lat'], $row['lon'], $stage->endPoint->lat, $stage->endPoint->lon),
                source: 'datatourisme',
            );
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventToArray(Event $e): array
    {
        return [
            'name' => $e->name,
            'type' => $e->type,
            'lat' => $e->lat,
            'lon' => $e->lon,
            'startDate' => $e->startDate->format(\DateTimeInterface::ATOM),
            'endDate' => $e->endDate->format(\DateTimeInterface::ATOM),
            'url' => $e->url,
            'description' => $e->description,
            'priceMin' => $e->priceMin,
            'distanceToEndPoint' => $e->distanceToEndPoint,
            'source' => $e->source,
            'wikidataId' => $e->wikidataId,
            'imageUrl' => $e->imageUrl,
            'wikipediaUrl' => $e->wikipediaUrl,
            'openingHours' => $e->openingHours,
        ];
    }
}
