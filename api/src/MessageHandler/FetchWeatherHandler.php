<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeWind;
use App\Message\CheckFords;
use App\Message\FetchWeather;
use App\Repository\TripRequestRepositoryInterface;
use App\Weather\RawForecast;
use App\Weather\RawHourlySlot;
use App\Weather\RelativeWindCalculator;
use App\Weather\WeatherForecastDeriver;
use App\Weather\WeatherForecastSerializer;
use App\Weather\WeatherProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class FetchWeatherHandler extends AbstractTripMessageHandler
{
    /** Open-Meteo forecast horizon: no reliable hourly beyond ~16 days out. */
    private const int FORECAST_HORIZON_DAYS = 16;

    private const int CACHE_TTL_SECONDS = 10800; // 3 hours

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private WeatherProviderInterface $weatherProvider,
        #[Autowire(service: 'cache.weather')]
        private CacheItemPoolInterface $weatherCache,
        private RiderTimeEstimatorInterface $riderTimeEstimator,
        private WeatherForecastDeriver $deriver,
        private WeatherForecastSerializer $serializer,
        MessageBusInterface $messageBus,
        private RelativeWindCalculator $relativeWindCalculator = new RelativeWindCalculator(),
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(FetchWeather $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripStateManager->getRequest($tripId);
        $stages = $this->tripStateManager->getStages($tripId);

        if (!$request instanceof TripRequest || null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::WEATHER, function () use ($tripId, $request, $stages, $locale, $generation): void {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
            $horizonEnd = $today->modify(\sprintf('+%d days', self::FORECAST_HORIZON_DAYS));
            $baseDate = $request->startDate ?? $today;

            // Phase 1: per-stage context (date/window/bearing) + raw cache lookup.
            /** @var array<int, array{lat: float, lon: float, localDate: string, startHour: float, endHour: float, bearing: float|null, cacheKey: string}> $contexts */
            $contexts = [];
            /** @var array<int, ?RawForecast> $rawByStage */
            $rawByStage = [];
            /** @var array<int, array{lat: float, lon: float}> $uncached */
            $uncached = [];

            foreach ($stages as $i => $stage) {
                $lat = $stage->startPoint->lat;
                $lon = $stage->startPoint->lon;
                $stageDate = $baseDate->modify(\sprintf('+%d days', $stage->dayNumber - 1));
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
                    'cacheKey' => \sprintf('weather2.%s.%s.%s', round($lat, 2), round($lon, 2), $localDate),
                ];

                // Beyond the forecast horizon (or in the past): no forecast, no fetch.
                if ($stageDate < $today || $stageDate > $horizonEnd) {
                    $rawByStage[$i] = null;
                    continue;
                }

                $item = $this->weatherCache->getItem($contexts[$i]['cacheKey']);
                if ($item->isHit()) {
                    /** @var array{tz: string, slots: list<array{t: string, temp: float, app: float, pmm: float, pprob: int, ws: float, wg: float, wd: int, hum: int, uv: float, code: int}>} $cached */
                    $cached = $item->get();
                    $rawByStage[$i] = $this->rawFromCache($cached);
                } else {
                    $uncached[$i] = ['lat' => $lat, 'lon' => $lon];
                }
            }

            // Phase 2: batch-fetch uncached locations over the covering date range.
            if ([] !== $uncached) {
                $indices = array_keys($uncached);
                $dates = array_map(static fn (int $i): string => $contexts[$i]['localDate'], $indices);
                $rangeStart = new \DateTimeImmutable(min($dates), new \DateTimeZone('UTC'));
                // +1 day so a riding window that crosses midnight can read the next
                // day's early hours (the deriver anchors the window on local midnight).
                $rangeEnd = new \DateTimeImmutable(max($dates), new \DateTimeZone('UTC'))->modify('+1 day');

                try {
                    $forecasts = $this->weatherProvider->fetchForecasts(array_values($uncached), $rangeStart, $rangeEnd);

                    foreach ($forecasts as $idx => $raw) {
                        $stageIndex = $indices[$idx];
                        if (!$raw instanceof RawForecast) {
                            continue;
                        }

                        // Cache the stage day plus the following day, so a window
                        // crossing midnight has its post-midnight hours available and
                        // the cache stays independent of pace/departure.
                        $localDate = $contexts[$stageIndex]['localDate'];
                        $nextDate = new \DateTimeImmutable($localDate)->modify('+1 day')->format('Y-m-d');
                        $daySlots = array_merge($raw->slotsForDate($localDate), $raw->slotsForDate($nextDate));
                        $dayRaw = new RawForecast($raw->timezone, $daySlots);
                        if ([] === $dayRaw->slots) {
                            continue;
                        }

                        $rawByStage[$stageIndex] = $dayRaw;

                        $item = $this->weatherCache->getItem($contexts[$stageIndex]['cacheKey']);
                        $item->set($this->rawToCache($dayRaw));
                        $item->expiresAfter(self::CACHE_TTL_SECONDS);
                        $this->weatherCache->save($item);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Batch weather fetch failed.', ['error' => $e->getMessage()]);
                }
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
                $this->tripStateManager->updateStageWeather($tripId, $stage->dayNumber, $stage->weather);
            }

            $this->publisher->publish($tripId, MercureEventType::WEATHER_FETCHED, [
                'stagesWithWeather' => \count(array_filter(
                    $stages,
                    static fn (Stage $s): bool => $s->weather instanceof WeatherForecast
                )),
                'stages' => array_map(
                    fn (Stage $s): array => [
                        'dayNumber' => $s->dayNumber,
                        'weather' => $s->weather instanceof WeatherForecast ? $this->serializer->toArray($s->weather) : null,
                    ],
                    $stages
                ),
            ]);

            $this->messageBus->dispatch(new AnalyzeWind($tripId, $generation));
            // Ford severity depends on the per-stage forecast, so run it after weather.
            $this->messageBus->dispatch(new CheckFords($tripId, $generation));
        }, $generation);
    }

    /**
     * @return array{tz: string, slots: list<array<string, mixed>>}
     */
    private function rawToCache(RawForecast $raw): array
    {
        return [
            'tz' => $raw->timezone->getName(),
            'slots' => array_map(static fn (RawHourlySlot $s): array => [
                't' => $s->time->format(\DateTimeInterface::ATOM),
                'temp' => $s->temp,
                'app' => $s->apparentTemp,
                'pmm' => $s->precipitationMm,
                'pprob' => $s->precipitationProbability,
                'ws' => $s->windSpeed,
                'wg' => $s->windGusts,
                'wd' => $s->windDirectionDeg,
                'hum' => $s->humidity,
                'uv' => $s->uvIndex,
                'code' => $s->weatherCode,
            ], $raw->slots),
        ];
    }

    /**
     * @param array{tz: string, slots: list<array{t: string, temp: float, app: float, pmm: float, pprob: int, ws: float, wg: float, wd: int, hum: int, uv: float, code: int}>} $cached
     */
    private function rawFromCache(array $cached): RawForecast
    {
        try {
            $tz = new \DateTimeZone($cached['tz']);
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        $slots = [];
        foreach ($cached['slots'] as $s) {
            $slots[] = new RawHourlySlot(
                time: new \DateTimeImmutable($s['t']),
                temp: $s['temp'],
                apparentTemp: $s['app'],
                precipitationMm: $s['pmm'],
                precipitationProbability: $s['pprob'],
                windSpeed: $s['ws'],
                windGusts: $s['wg'],
                windDirectionDeg: $s['wd'],
                humidity: $s['hum'],
                uvIndex: $s['uv'],
                weatherCode: $s['code'],
            );
        }

        return new RawForecast($tz, $slots);
    }
}
