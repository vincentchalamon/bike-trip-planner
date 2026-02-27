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
use App\Weather\OpenMeteoProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
final readonly class FetchWeatherHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private OpenMeteoProvider $weatherProvider,
        #[Autowire(service: 'cache.weather')]
        private CacheInterface $weatherCache,
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
            foreach ($stages as $i => $stage) {
                $lat = $stage->startPoint->lat;
                $lon = $stage->startPoint->lon;
                $cacheKey = \sprintf('weather.%s.%s.%s.%d', $locale, round($lat, 2), round($lon, 2), $i);

                try {
                    /** @var array{icon: string, description: string, tempMin: float, tempMax: float, windSpeed: float, windDirection: string, precipitationProbability: int} $weatherData */
                    $weatherData = $this->weatherCache->get($cacheKey, function (ItemInterface $item) use ($lat, $lon, $locale): array {
                        $item->expiresAfter(10800); // 3 hours

                        $forecast = $this->weatherProvider->fetchForecast($lat, $lon, $locale);

                        return [
                            'icon' => $forecast->icon,
                            'description' => $forecast->description,
                            'tempMin' => $forecast->tempMin,
                            'tempMax' => $forecast->tempMax,
                            'windSpeed' => $forecast->windSpeed,
                            'windDirection' => $forecast->windDirection,
                            'precipitationProbability' => $forecast->precipitationProbability,
                        ];
                    });

                    $stage->weather = new WeatherForecast(
                        icon: $weatherData['icon'],
                        description: $weatherData['description'],
                        tempMin: $weatherData['tempMin'],
                        tempMax: $weatherData['tempMax'],
                        windSpeed: $weatherData['windSpeed'],
                        windDirection: $weatherData['windDirection'],
                        precipitationProbability: $weatherData['precipitationProbability'],
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning('Weather fetch failed for stage.', [
                        'stageIndex' => $i,
                        'error' => $e->getMessage(),
                    ]);
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
