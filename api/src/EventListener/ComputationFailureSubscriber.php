<?php

declare(strict_types=1);

namespace App\EventListener;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\AnalyzeTerrain;
use App\Message\AnalyzeWind;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckFerries;
use App\Message\CheckFords;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchAndParseRoute;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use App\Service\TripCompletionGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Marks a pipeline computation as terminally `failed` once its Messenger retries
 * are exhausted, then re-evaluates the terminal gate (recette #649, Lot 1).
 *
 * Why an event listener rather than the handler's catch block:
 * {@see \App\MessageHandler\AbstractTripMessageHandler} re-throws on every failed
 * attempt so Messenger can retry. Only the framework knows whether a given
 * failure is the last one — it exposes that decision via
 * {@see WorkerMessageFailedEvent::willRetry()}, set by the core
 * `SendFailedMessageForRetryListener` (priority 100). This listener runs at the
 * default priority (0), i.e. afterwards, so `willRetry()` is final. While retries
 * remain, the computation stays `running` and we do nothing; once exhausted, we
 * mark it `failed` so the gate's `completed + failed === total` condition can
 * finally hold and the terminal TRIP_COMPLETE / TRIP_READY event is published.
 *
 * Without this, any handler whose retries are exhausted would leave its
 * computation stuck and the frontend waiting forever (loader infini).
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class ComputationFailureSubscriber
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
        private TripCompletionGate $completionGate,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        // A retry is still scheduled: the computation may yet succeed, so leave
        // it `running` and let the next attempt settle it.
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        $computation = self::resolveComputation($message);
        if (null === $computation) {
            return;
        }

        /** @var object{tripId: string} $message */
        $tripId = $message->tripId;

        $this->computationTracker->markFailed($tripId, $computation);

        // wind and fords are dispatched only at the end of FetchWeatherHandler and
        // depend on the per-stage forecast. If WEATHER never settles successfully,
        // they are never dispatched and would stay `pending` forever, making the
        // gate's total unreachable. Cascade the failure so they reach a terminal
        // state too.
        if (ComputationName::WEATHER === $computation) {
            $this->computationTracker->markFailed($tripId, ComputationName::WIND);
            $this->computationTracker->markFailed($tripId, ComputationName::FORDS);
        }

        $this->logger->warning('Computation {computation} exhausted its retries for trip {tripId}; marked failed.', [
            'computation' => $computation->value,
            'tripId' => $tripId,
        ]);

        $this->completionGate->evaluate($tripId);
    }

    /**
     * Maps an enrichment message to the pipeline computation it tracks.
     *
     * Returns null for messages that are not part of the gated pipeline (e.g.
     * on-demand recalculations or LLM analyses tracked separately), so their
     * failure does not disturb the gate.
     */
    private static function resolveComputation(object $message): ?ComputationName
    {
        return match ($message::class) {
            FetchAndParseRoute::class => ComputationName::ROUTE,
            GenerateStages::class => ComputationName::STAGES,
            ScanPois::class => ComputationName::POIS,
            ScanAccommodations::class => ComputationName::ACCOMMODATIONS,
            AnalyzeTerrain::class => ComputationName::TERRAIN,
            FetchWeather::class => ComputationName::WEATHER,
            CheckCalendar::class => ComputationName::CALENDAR,
            AnalyzeWind::class => ComputationName::WIND,
            CheckBikeShops::class => ComputationName::BIKE_SHOPS,
            CheckWaterPoints::class => ComputationName::WATER_POINTS,
            CheckCulturalPois::class => ComputationName::CULTURAL_POIS,
            CheckRailwayStations::class => ComputationName::RAILWAY_STATIONS,
            CheckHealthServices::class => ComputationName::HEALTH_SERVICES,
            CheckBorderCrossing::class => ComputationName::BORDER_CROSSING,
            CheckFerries::class => ComputationName::FERRIES,
            CheckFords::class => ComputationName::FORDS,
            ScanEvents::class => ComputationName::EVENTS,
            default => null,
        };
    }
}
