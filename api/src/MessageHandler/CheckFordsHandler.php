<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Stage;
use App\ApiResource\Model\WeatherForecast;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertCode;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckFords;
use App\Osm\FordRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Flags stages whose route crosses a ford, contextualised by the weather.
 *
 * Dispatched after {@see FetchWeatherHandler} (like AnalyzeWind) so each stage's
 * forecast is available. A ford emits a nudge in dry weather, escalated to a
 * warning when rain is forecast for the stage — a ford can be impassable in high
 * water. Deduplicates per stage by ford name.
 */
#[AsMessageHandler]
final readonly class CheckFordsHandler extends AbstractRouteCrossingHandler
{
    /** Max distance (m) between the stage line and a ford to count the stage as crossing it. */
    private const int FORD_TOLERANCE_METERS = 25;

    /** Precipitation probability (%) at or above which a ford is escalated to a warning. */
    private const int RAIN_THRESHOLD_PERCENT = 50;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        TripRequestRepositoryInterface $tripRequestRepository,
        TripStageStoreInterface $stageStore,
        private FordRepositoryInterface $fordRepository,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripRequestRepository, $stageStore, $messageBus, $alertRenderer);
    }

    public function __invoke(CheckFords $message): void
    {
        $this->checkCrossings(
            $message->tripId,
            ComputationName::FORDS,
            AlertGroup::FORD,
            MercureEventType::FORD_ALERTS,
            fn (array $stagePoints): array => $this->fordRepository->findNearStage($stagePoints, self::FORD_TOLERANCE_METERS),
            static function (Stage $stage, array $ford): Alert {
                $raining = $stage->weather instanceof WeatherForecast
                    && $stage->weather->precipitationProbability >= self::RAIN_THRESHOLD_PERCENT;

                return new Alert(
                    code: $raining ? AlertCode::FORD_CROSSING_WET : AlertCode::FORD_CROSSING_DRY,
                    type: $raining ? AlertType::WARNING : AlertType::NUDGE,
                    messageKey: $raining ? 'alert.ford.warning' : 'alert.ford.nudge',
                    lat: $ford['lat'],
                    lon: $ford['lon'],
                    action: new AlertAction(AlertActionKind::NAVIGATE, 'alert.ford.action', ['lat' => $ford['lat'], 'lon' => $ford['lon']]),
                );
            },
        );
    }
}
