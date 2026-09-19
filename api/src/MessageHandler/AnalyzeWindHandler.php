<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertCode;
use App\Enum\AlertGroup;
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
            /** @var list<Stage> $headwindStages */
            $headwindStages = [];
            /** @var list<Stage> $poorComfortStages */
            $poorComfortStages = [];
            /** @var list<Stage> $heatStages */
            $heatStages = [];
            /** @var list<Stage> $coldStages */
            $coldStages = [];
            /** @var list<Stage> $rainStages */
            $rainStages = [];
            /** @var list<Stage> $gustStages */
            $gustStages = [];

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
                    $headwindStages[] = $stage;
                }

                // Count stages with poor comfort index
                if ($weather->comfortIndex <= self::COMFORT_INDEX_POOR_THRESHOLD) {
                    $poorComfortStages[] = $stage;
                }

                // The apparent-temperature / rain-mm / gust thresholds are only
                // meaningful once the hourly derivation has populated those fields;
                // skip legacy/partial forecasts that carry defaults.
                if ([] === $weather->hourly) {
                    continue;
                }

                if ($weather->apparentTempMax >= self::HEAT_APPARENT_MAX_C) {
                    $heatStages[] = $stage;
                }

                if ($weather->apparentTempMin <= self::COLD_APPARENT_MIN_C) {
                    $coldStages[] = $stage;
                }

                if ($weather->precipitationMm >= self::RAIN_HEAVY_MM) {
                    $rainStages[] = $stage;
                }

                if ($weather->windGusts >= self::WIND_GUSTS_STRONG_KMH) {
                    $gustStages[] = $stage;
                }
            }

            $stagesWithWeather = \count(array_filter($stages, static fn (Stage $s): bool => $s->weather instanceof WeatherForecast));

            $dismissAction = new AlertAction(
                kind: AlertActionKind::DISMISS,
                label: $this->translator->trans('alert.wind.action', [], 'alerts', $locale),
            );

            $alerts = [];

            // The headwind rule stays trip-wide in what triggers it — it is about a trip
            // spent riding into the wind, not about one windy day — so the ratio threshold
            // is unchanged. Only the placement changes: the alert now sits on the stages
            // that actually carry the headwind instead of being counted up into one message
            // pinned to the first day (ADR-066).
            if (
                $stagesWithWeather > 0
                && (\count($headwindStages) / $stagesWithWeather) >= self::HEADWIND_RATIO_THRESHOLD
            ) {
                foreach ($headwindStages as $stage) {
                    $alerts[] = $this->stageAlert(
                        $stage,
                        AlertCode::WIND_HEADWIND,
                        'alert.wind.stage',
                        ['%threshold%' => $this->decimalFormatter->format(self::WIND_SPEED_THRESHOLD_KMH, $locale)],
                        $dismissAction,
                        $locale,
                    );
                }
            }

            foreach ($poorComfortStages as $stage) {
                $alerts[] = $this->stageAlert($stage, AlertCode::COMFORT_POOR_CONDITIONS, 'alert.comfort.stage', [], $dismissAction, $locale);
            }

            foreach ($heatStages as $stage) {
                $alerts[] = $this->stageAlert(
                    $stage,
                    AlertCode::HEAT_EXTREME,
                    'alert.heat.stage',
                    ['%threshold%' => $this->decimalFormatter->format(self::HEAT_APPARENT_MAX_C, $locale)],
                    $dismissAction,
                    $locale,
                );
            }

            foreach ($coldStages as $stage) {
                $alerts[] = $this->stageAlert(
                    $stage,
                    AlertCode::COLD_EXTREME,
                    'alert.cold.stage',
                    ['%threshold%' => $this->decimalFormatter->format(self::COLD_APPARENT_MIN_C, $locale)],
                    $dismissAction,
                    $locale,
                );
            }

            foreach ($rainStages as $stage) {
                $alerts[] = $this->stageAlert(
                    $stage,
                    AlertCode::RAIN_HEAVY,
                    'alert.rain.stage',
                    ['%threshold%' => $this->decimalFormatter->format(self::RAIN_HEAVY_MM, $locale)],
                    $dismissAction,
                    $locale,
                );
            }

            foreach ($gustStages as $stage) {
                $alerts[] = $this->stageAlert(
                    $stage,
                    AlertCode::WIND_GUSTS_STRONG,
                    'alert.gusts.stage',
                    ['%threshold%' => $this->decimalFormatter->format(self::WIND_GUSTS_STRONG_KMH, $locale)],
                    $dismissAction,
                    $locale,
                );
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, and without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->tripStateManager->updateTripAlertsForGroup($tripId, AlertGroup::WIND, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, MercureEventType::WIND_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    private function stageAlert(Stage $stage, AlertCode $code, string $key, array $parameters, AlertAction $action, string $locale): array
    {
        return [
            'stageId' => $stage->id,
            'dayNumber' => $stage->dayNumber,
            'code' => $code->value,
            'type' => AlertType::WARNING->value,
            'message' => $this->translator->trans($key, $parameters, 'alerts', $locale),
            'action' => [
                'kind' => $action->kind->value,
                'label' => $action->label,
                'payload' => $action->payload,
            ],
        ];
    }
}
