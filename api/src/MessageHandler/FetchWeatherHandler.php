<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeWind;
use App\Message\FetchWeather;
use App\Repository\TripRequestRepositoryInterface;
use App\Weather\WeatherProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class FetchWeatherHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private WeatherProviderInterface $weatherProvider,
        #[Autowire(service: 'cache.weather')]
        private CacheItemPoolInterface $weatherCache,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(FetchWeather $message): void
    {
        $tripId = $message->tripId;
        $request = $this->tripStateManager->getRequest($tripId);
        $stages = $this->tripStateManager->getStages($tripId);

        if (!$request instanceof TripRequest || null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::WEATHER, function () use ($tripId, $stages, $locale): void {
            // Phase 1: Check cache for each stage, collect misses
            /** @var array<int, string> $cacheKeys */
            $cacheKeys = [];
            /** @var array<int, array{lat: float, lon: float}> $uncachedLocations */
            $uncachedLocations = [];

            foreach ($stages as $i => $stage) {
                $lat = $stage->startPoint->lat;
                $lon = $stage->startPoint->lon;
                $cacheKey = \sprintf('weather.%s.%s.%s.%d', $locale, round($lat, 2), round($lon, 2), $i);
                $cacheKeys[$i] = $cacheKey;

                $cacheItem = $this->weatherCache->getItem($cacheKey);
                if ($cacheItem->isHit()) {
                    /** @var array{icon: string, description: string, tempMin: float, tempMax: float, windSpeed: float, windDirection: string, precipitationProbability: int, humidity: int, comfortIndex: int} $weatherData */
                    $weatherData = $cacheItem->get();

                    $stage->weather = new WeatherForecast(
                        icon: $weatherData['icon'],
                        description: $weatherData['description'],
                        tempMin: $weatherData['tempMin'],
                        tempMax: $weatherData['tempMax'],
                        windSpeed: $weatherData['windSpeed'],
                        windDirection: $weatherData['windDirection'],
                        precipitationProbability: $weatherData['precipitationProbability'],
                        humidity: $weatherData['humidity'] ?? 50,
                        comfortIndex: $weatherData['comfortIndex'] ?? 100,
                        relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
                    );
                } else {
                    $uncachedLocations[$i] = ['lat' => $lat, 'lon' => $lon];
                }
            }

            // Phase 2: Batch fetch all uncached locations in a single API call
            if ([] !== $uncachedLocations) {
                $uncachedIndices = array_keys($uncachedLocations);
                $locations = array_values($uncachedLocations);

                try {
                    $forecasts = $this->weatherProvider->fetchForecasts($locations, $locale);

                    foreach ($forecasts as $idx => $forecast) {
                        $stageIndex = $uncachedIndices[$idx];
                        $stages[$stageIndex]->weather = $forecast;

                        // Store in cache
                        $cacheItem = $this->weatherCache->getItem($cacheKeys[$stageIndex]);
                        $cacheItem->set([
                            'icon' => $forecast->icon,
                            'description' => $forecast->description,
                            'tempMin' => $forecast->tempMin,
                            'tempMax' => $forecast->tempMax,
                            'windSpeed' => $forecast->windSpeed,
                            'windDirection' => $forecast->windDirection,
                            'precipitationProbability' => $forecast->precipitationProbability,
                            'humidity' => $forecast->humidity,
                            'comfortIndex' => $forecast->comfortIndex,
                        ]);
                        $cacheItem->expiresAfter(10800); // 3 hours
                        $this->weatherCache->save($cacheItem);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Batch weather fetch failed.', ['error' => $e->getMessage()]);
                }
            }

            // Phase 3: Compute relative wind direction for each stage
            foreach ($stages as $stage) {
                if (null === $stage->weather) {
                    continue;
                }

                $bearing = $this->computeStageBearing($stage);
                if (null !== $bearing) {
                    $stage->weather = $this->withRelativeWindDirection($stage->weather, $bearing);
                }
            }

            $this->tripStateManager->storeStages($tripId, $stages);

            $this->publisher->publish($tripId, MercureEventType::WEATHER_FETCHED, [
                'stagesWithWeather' => \count(array_filter(
                    $stages,
                    static fn (Stage $s): bool => $s->weather instanceof WeatherForecast
                )),
                'stages' => array_map(
                    static fn (Stage $s): array => [
                        'dayNumber' => $s->dayNumber,
                        'weather' => $s->weather instanceof WeatherForecast ? [
                            'icon' => $s->weather->icon,
                            'description' => $s->weather->description,
                            'tempMin' => $s->weather->tempMin,
                            'tempMax' => $s->weather->tempMax,
                            'windSpeed' => round($s->weather->windSpeed, 1),
                            'windDirection' => $s->weather->windDirection,
                            'precipitationProbability' => $s->weather->precipitationProbability,
                            'humidity' => $s->weather->humidity,
                            'comfortIndex' => $s->weather->comfortIndex,
                            'relativeWindDirection' => $s->weather->relativeWindDirection,
                        ] : null,
                    ],
                    $stages
                ),
            ]);

            $this->messageBus->dispatch(new AnalyzeWind($tripId));
        });
    }

    /**
     * Compute the bearing (degrees, 0 = North, clockwise) from stage start to end.
     * Returns null if start and end are identical (rest day or trivial stage).
     */
    private function computeStageBearing(Stage $stage): ?float
    {
        $lat1 = deg2rad($stage->startPoint->lat);
        $lat2 = deg2rad($stage->endPoint->lat);
        $deltaLon = deg2rad($stage->endPoint->lon - $stage->startPoint->lon);

        $x = sin($deltaLon) * cos($lat2);
        $y = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($deltaLon);
        $bearing = fmod(rad2deg(atan2($x, $y)) + 360, 360);

        // If start and end are practically the same point, bearing is undefined
        $dist = abs($stage->endPoint->lat - $stage->startPoint->lat)
            + abs($stage->endPoint->lon - $stage->startPoint->lon);

        if ($dist < 1e-6) {
            return null;
        }

        return $bearing;
    }

    /**
     * Compute the relative wind direction (headwind / tailwind / crosswind)
     * given the stage bearing and the absolute wind direction (degrees from North).
     *
     * The wind direction from Open-Meteo indicates where the wind comes FROM.
     * The cyclist travels in the direction of `$stageBearing`.
     * Relative angle = |wind_from - (stage_bearing + 180°)|, normalised to [0, 180].
     *   - 0° → pure headwind (wind comes from directly ahead)
     *   - 180° → pure tailwind (wind comes from behind)
     *   - 90° → pure crosswind
     * Thresholds: headwind ≤ 60°, tailwind ≤ 60° (i.e. relative ≥ 120°), else crosswind.
     */
    private function withRelativeWindDirection(WeatherForecast $forecast, float $stageBearing): WeatherForecast
    {
        $windDeg = $this->directionToDeg($forecast->windDirection);
        if (null === $windDeg) {
            return $forecast;
        }

        // Angle between wind-from direction and cyclist's forward direction
        // Wind comes FROM windDeg, cyclist goes TO stageBearing
        $diff = fmod(abs($windDeg - $stageBearing), 360);
        if ($diff > 180) {
            $diff = 360 - $diff;
        }

        // diff: 0 = wind from same direction as travel (tailwind), 180 = headwind
        $relativeWindDirection = match (true) {
            $diff >= 120 => WeatherForecast::RELATIVE_WIND_HEADWIND,
            $diff <= 60 => WeatherForecast::RELATIVE_WIND_TAILWIND,
            default => WeatherForecast::RELATIVE_WIND_CROSSWIND,
        };

        return new WeatherForecast(
            icon: $forecast->icon,
            description: $forecast->description,
            tempMin: $forecast->tempMin,
            tempMax: $forecast->tempMax,
            windSpeed: $forecast->windSpeed,
            windDirection: $forecast->windDirection,
            precipitationProbability: $forecast->precipitationProbability,
            humidity: $forecast->humidity,
            comfortIndex: $forecast->comfortIndex,
            relativeWindDirection: $relativeWindDirection,
        );
    }

    /**
     * Convert compass direction string to degrees (where the wind comes FROM).
     * Returns null for unknown strings.
     */
    private function directionToDeg(string $direction): ?float
    {
        return match ($direction) {
            'N' => 0.0,
            'NE' => 45.0,
            'E' => 90.0,
            'SE' => 135.0,
            'S' => 180.0,
            'SO' => 225.0,
            'O' => 270.0,
            'NO' => 315.0,
            default => null,
        };
    }
}
