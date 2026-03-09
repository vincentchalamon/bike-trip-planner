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
                    /** @var array{icon: string, description: string, tempMin: float, tempMax: float, windSpeed: float, windDirection: string, precipitationProbability: int} $weatherData */
                    $weatherData = $cacheItem->get();

                    $stage->weather = new WeatherForecast(
                        icon: $weatherData['icon'],
                        description: $weatherData['description'],
                        tempMin: $weatherData['tempMin'],
                        tempMax: $weatherData['tempMax'],
                        windSpeed: $weatherData['windSpeed'],
                        windDirection: $weatherData['windDirection'],
                        precipitationProbability: $weatherData['precipitationProbability'],
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
                        ]);
                        $cacheItem->expiresAfter(10800); // 3 hours
                        $this->weatherCache->save($cacheItem);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Batch weather fetch failed.', ['error' => $e->getMessage()]);
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
                        ] : null,
                    ],
                    $stages
                ),
            ]);

            $this->messageBus->dispatch(new AnalyzeWind($tripId));
        });
    }
}
