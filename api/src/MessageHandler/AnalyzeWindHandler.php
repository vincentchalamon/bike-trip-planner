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
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Format\DecimalFormatter;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeWind;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class AnalyzeWindHandler extends AbstractTripMessageHandler
{
    private const float WIND_SPEED_THRESHOLD_KMH = 25.0;

    private const float HEADWIND_RATIO_THRESHOLD = 0.6; // 60%

    private const int COMFORT_INDEX_POOR_THRESHOLD = 39;

    /** Apparent temperature at or above this (°C) flags a heat-risk stage. */
    private const float HEAT_APPARENT_MAX_C = 32.0;

    /** Apparent temperature at or below this (°C) flags a cold-risk stage. */
    private const float COLD_APPARENT_MIN_C = 2.0;

    /** Total precipitation over the riding window at or above this (mm) flags heavy rain. */
    private const float RAIN_HEAVY_MM = 10.0;

    /** Wind gusts at or above this (km/h) flag a strong-gust stage. */
    private const float WIND_GUSTS_STRONG_KMH = 50.0;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private TranslatorInterface $translator,
        private DecimalFormatter $decimalFormatter,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
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
            $heatCount = 0;
            $coldCount = 0;
            $rainCount = 0;
            $gustCount = 0;

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

                // The apparent-temperature / rain-mm / gust thresholds are only
                // meaningful once the hourly derivation has populated those fields;
                // skip legacy/partial forecasts that carry defaults.
                if ([] === $weather->hourly) {
                    continue;
                }

                if ($weather->apparentTempMax >= self::HEAT_APPARENT_MAX_C) {
                    ++$heatCount;
                }

                if ($weather->apparentTempMin <= self::COLD_APPARENT_MIN_C) {
                    ++$coldCount;
                }

                if ($weather->precipitationMm >= self::RAIN_HEAVY_MM) {
                    ++$rainCount;
                }

                if ($weather->windGusts >= self::WIND_GUSTS_STRONG_KMH) {
                    ++$gustCount;
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
                    [
                        '%count%' => $headwindCount,
                        '%total%' => $stagesWithWeather,
                        '%threshold%' => $this->decimalFormatter->format(self::WIND_SPEED_THRESHOLD_KMH, $locale),
                    ],
                    'alerts',
                    $locale,
                );
                $alert = new Alert(code: AlertCode::WIND_HEADWIND, type: AlertType::WARNING, message: $message);
                $alerts[] = [
                    'code' => $alert->code?->value,
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
                $alert = new Alert(code: AlertCode::COMFORT_POOR_CONDITIONS, type: AlertType::WARNING, message: $message);
                $alerts[] = [
                    'code' => $alert->code?->value,
                    'type' => $alert->type->value,
                    'message' => $alert->message,
                    'action' => [
                        'kind' => $dismissAction->kind->value,
                        'label' => $dismissAction->label,
                        'payload' => $dismissAction->payload,
                    ],
                ];
            }

            if ($heatCount > 0) {
                $alerts[] = $this->alertPayload(
                    AlertCode::HEAT_EXTREME,
                    $this->translator->trans(
                        'alert.heat.warning',
                        ['%count%' => $heatCount, '%threshold%' => $this->decimalFormatter->format(self::HEAT_APPARENT_MAX_C, $locale)],
                        'alerts',
                        $locale,
                    ),
                    $dismissAction,
                );
            }

            if ($coldCount > 0) {
                $alerts[] = $this->alertPayload(
                    AlertCode::COLD_EXTREME,
                    $this->translator->trans(
                        'alert.cold.warning',
                        ['%count%' => $coldCount, '%threshold%' => $this->decimalFormatter->format(self::COLD_APPARENT_MIN_C, $locale)],
                        'alerts',
                        $locale,
                    ),
                    $dismissAction,
                );
            }

            if ($rainCount > 0) {
                $alerts[] = $this->alertPayload(
                    AlertCode::RAIN_HEAVY,
                    $this->translator->trans(
                        'alert.rain.warning',
                        ['%count%' => $rainCount, '%threshold%' => $this->decimalFormatter->format(self::RAIN_HEAVY_MM, $locale)],
                        'alerts',
                        $locale,
                    ),
                    $dismissAction,
                );
            }

            if ($gustCount > 0) {
                $alerts[] = $this->alertPayload(
                    AlertCode::WIND_GUSTS_STRONG,
                    $this->translator->trans(
                        'alert.gusts.warning',
                        ['%count%' => $gustCount, '%threshold%' => $this->decimalFormatter->format(self::WIND_GUSTS_STRONG_KMH, $locale)],
                        'alerts',
                        $locale,
                    ),
                    $dismissAction,
                );
            }

            $this->publisher->publish($tripId, MercureEventType::WIND_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }

    /**
     * @return array<string, mixed>
     */
    private function alertPayload(AlertCode $code, string $message, AlertAction $action): array
    {
        return [
            'code' => $code->value,
            'type' => AlertType::WARNING->value,
            'message' => $message,
            'action' => [
                'kind' => $action->kind->value,
                'label' => $action->label,
                'payload' => $action->payload,
            ],
        ];
    }
}
