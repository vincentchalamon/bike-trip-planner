<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckFords;
use App\Osm\FordRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flags stages whose route crosses a ford, contextualised by the weather.
 *
 * Dispatched after {@see FetchWeatherHandler} (like AnalyzeWind) so each stage's
 * forecast is available. A ford emits a nudge in dry weather, escalated to a
 * warning when rain is forecast for the stage — a ford can be impassable in high
 * water. Deduplicates per stage by ford name.
 */
#[AsMessageHandler]
final readonly class CheckFordsHandler extends AbstractTripMessageHandler
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
        private TripRequestRepositoryInterface $tripStateManager,
        private FordRepositoryInterface $fordRepository,
        private TranslatorInterface $translator,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(CheckFords $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::FORDS, function () use ($tripId, $stages, $locale): void {
            $alerts = [];

            foreach ($stages as $i => $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                $stagePoints = array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                    $stage->geometry,
                );

                $raining = $stage->weather instanceof WeatherForecast
                    && $stage->weather->precipitationProbability >= self::RAIN_THRESHOLD_PERCENT;

                /** @var list<string> $seenNames */
                $seenNames = [];
                foreach ($this->fordRepository->findNearStage($stagePoints, self::FORD_TOLERANCE_METERS) as $ford) {
                    $key = $ford['name'] ?? \sprintf('%.5F,%.5F', $ford['lat'], $ford['lon']);
                    if (\in_array($key, $seenNames, true)) {
                        continue;
                    }

                    $seenNames[] = $key;

                    $alerts[] = [
                        'stageIndex' => $i,
                        'dayNumber' => $stage->dayNumber,
                        'type' => ($raining ? AlertType::WARNING : AlertType::NUDGE)->value,
                        'message' => $this->translator->trans(
                            $raining ? 'alert.ford.warning' : 'alert.ford.nudge',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                        'action' => [
                            'kind' => AlertActionKind::NAVIGATE->value,
                            'label' => $this->translator->trans('alert.ford.action', [], 'alerts', $locale),
                            'payload' => ['lat' => $ford['lat'], 'lon' => $ford['lon']],
                        ],
                        'lat' => $ford['lat'],
                        'lon' => $ford['lon'],
                    ];
                }
            }

            $this->publisher->publish($tripId, MercureEventType::FORD_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }
}
