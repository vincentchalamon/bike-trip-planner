<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Engine\RiderTimeEstimatorInterface;
use App\Entity\User;
use App\Enum\WeatherAvailability;
use App\Mercure\MercureEventType;
use App\Message\AnalyzeWind;
use App\Message\CheckFords;
use App\Message\FetchWeather;
use App\Weather\RawForecast;
use App\Weather\RelativeWindCalculator;
use App\Weather\WeatherForecastDeriver;
use App\Weather\WeatherForecastSerializer;
use App\Weather\WeatherProviderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FetchWeatherHandler extends AbstractTripMessageHandler
{
    public function __construct(
        TripHandlerContext $context,
        private WeatherProviderInterface $weatherProvider,
        private RiderTimeEstimatorInterface $riderTimeEstimator,
        private WeatherForecastDeriver $deriver,
        private WeatherForecastSerializer $serializer,
        private RelativeWindCalculator $relativeWindCalculator = new RelativeWindCalculator(),
    ) {
        parent::__construct($context);
    }

    public function __invoke(FetchWeather $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripRequestRepository->getRequest($tripId);
        $stages = $this->stageStore->getStages($tripId);

        if (!$request instanceof TripRequest || null === $stages) {
            return;
        }

        $locale = $this->tripRequestRepository->getLocale($tripId) ?? User::FALLBACK_LOCALE;

        $this->executeWithTracking($message, function () use ($tripId, $request, $stages, $locale, $generation): void {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
            $horizonEnd = $today->modify(\sprintf('+%d days', WeatherAvailability::HORIZON_DAYS));
            $baseDate = $request->startDate ?? $today;

            // Phase 1: per-stage context (date/window/bearing).
            /** @var array<int, array{lat: float, lon: float, localDate: string, startHour: float, endHour: float, bearing: float|null}> $contexts */
            $contexts = [];
            /** @var list<array{lat: float, lon: float, date: string}> $requested */
            $requested = [];
            /** @var list<int> $requestedStages */
            $requestedStages = [];

            foreach ($stages as $i => $stage) {
                $lat = $stage->startPoint->lat;
                $lon = $stage->startPoint->lon;
                $stageDate = $stage->dateFrom($baseDate);
                $localDate = $stageDate->format('Y-m-d');

                $contexts[$i] = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'localDate' => $localDate,
                    'startHour' => (float) $request->departureHour,
                    'endHour' => $this->riderTimeEstimator->estimateTimeAtDistance(
                        $stage->distance,
                        $stage->distance,
                        $request->departureHour,
                        $request->averageSpeed,
                        $stage->elevation,
                    ),
                    'bearing' => $this->relativeWindCalculator->computeBearing($lat, $lon, $stage->endPoint->lat, $stage->endPoint->lon),
                ];

                // Beyond the forecast horizon (or in the past): no forecast, no fetch.
                if ($stageDate < $today || $stageDate > $horizonEnd) {
                    continue;
                }

                $requested[] = ['lat' => $lat, 'lon' => $lon, 'date' => $localDate];
                $requestedStages[] = $i;
            }

            // Phase 2: one batch for every stage day within the horizon.
            /** @var array<int, ?RawForecast> $rawByStage */
            $rawByStage = [];
            foreach ([] === $requested ? [] : $this->weatherProvider->fetchDayForecasts($requested) as $k => $raw) {
                $rawByStage[$requestedStages[$k]] = $raw;
            }

            // Phase 3: derive per-stage forecast for the actual riding window.
            foreach ($stages as $i => $stage) {
                $raw = $rawByStage[$i] ?? null;
                $ctx = $contexts[$i];
                $stage->weather = null === $raw
                    ? null
                    : $this->deriver->derive($raw, $ctx['localDate'], $ctx['startHour'], $ctx['endHour'], $ctx['bearing'], $locale);
            }

            // Persist each stage's weather with an atomic per-column UPDATE so a
            // slower sibling handler (pois/terrain) re-writing the whole collection
            // can no longer wipe it (recette #649).
            foreach ($stages as $stage) {
                $this->stageStore->updateStageWeather($tripId, $stage->id, $stage->weather);
            }

            $this->publisher->publish($tripId, MercureEventType::WEATHER_FETCHED, [
                'stages' => array_map(
                    fn (Stage $s): array => [
                        'stageId' => $s->id,
                        'weather' => $s->weather instanceof WeatherForecast ? $this->serializer->toArray($s->weather) : null,
                    ],
                    $stages
                ),
            ]);

            $this->messageBus->dispatch(new AnalyzeWind($tripId, $generation));
            // Ford severity depends on the per-stage forecast, so run it after weather.
            $this->messageBus->dispatch(new CheckFords($tripId, $generation));
        });
    }
}
