<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeWind;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class AnalyzeWindHandler extends AbstractTripMessageHandler
{
    private const float WIND_SPEED_THRESHOLD_KMH = 25.0;

    private const float HEADWIND_RATIO_THRESHOLD = 0.6; // 60%

    private const int COMFORT_INDEX_POOR_THRESHOLD = 39;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager);
    }

    public function __invoke(AnalyzeWind $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::WIND, function () use ($tripId, $stages, $locale): void {
            $alerts = [];
            $headwindCount = 0;
            $poorComfortCount = 0;

            foreach ($stages as $stage) {
                if (null === $stage->weather) {
                    continue;
                }

                $weather = $stage->weather;

                // Count headwind stages using the pre-computed relativeWindDirection
                if (
                    $weather->windSpeed >= self::WIND_SPEED_THRESHOLD_KMH
                    && WeatherForecast::RELATIVE_WIND_HEADWIND === $weather->relativeWindDirection
                ) {
                    ++$headwindCount;
                }

                // Count stages with poor comfort index
                if ($weather->comfortIndex <= self::COMFORT_INDEX_POOR_THRESHOLD) {
                    ++$poorComfortCount;
                }
            }

            $stagesWithWeather = \count(array_filter($stages, static fn (Stage $s): bool => $s->weather instanceof WeatherForecast));

            $dismissAction = new AlertAction(
                kind: AlertActionKind::DISMISS,
                label: $this->translator->trans('alert.wind.action', [], 'alerts', $locale),
            );

            if (
                $stagesWithWeather > 0
                && ($headwindCount / $stagesWithWeather) >= self::HEADWIND_RATIO_THRESHOLD
            ) {
                $message = $this->translator->trans(
                    'alert.wind.warning',
                    ['%count%' => $headwindCount, '%total%' => $stagesWithWeather],
                    'alerts',
                    $locale,
                );
                $alert = new Alert(type: AlertType::WARNING, message: $message);
                $alerts[] = [
                    'type' => $alert->type->value,
                    'message' => $alert->message,
                    'action' => [
                        'kind' => $dismissAction->kind->value,
                        'label' => $dismissAction->label,
                        'payload' => $dismissAction->payload,
                    ],
                ];
            }

            if ($stagesWithWeather > 0 && $poorComfortCount > 0) {
                $message = $this->translator->trans(
                    'alert.comfort.warning',
                    ['%count%' => $poorComfortCount, '%total%' => $stagesWithWeather],
                    'alerts',
                    $locale,
                );
                $alert = new Alert(type: AlertType::WARNING, message: $message);
                $alerts[] = [
                    'type' => $alert->type->value,
                    'message' => $alert->message,
                    'action' => [
                        'kind' => $dismissAction->kind->value,
                        'label' => $dismissAction->label,
                        'payload' => $dismissAction->payload,
                    ],
                ];
            }

            $this->publisher->publish($tripId, MercureEventType::WIND_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }
}
