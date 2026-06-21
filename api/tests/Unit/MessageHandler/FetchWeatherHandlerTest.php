<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\FetchWeather;
use App\MessageHandler\FetchWeatherHandler;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use App\Weather\WeatherProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class FetchWeatherHandlerTest extends TestCase
{
    private function stage(int $day, float $lat, float $lon): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: $day,
            distance: 60.0,
            elevation: 100.0,
            startPoint: new Coordinate($lat, $lon),
            endPoint: new Coordinate($lat + 0.1, $lon + 0.1),
            geometry: [new Coordinate($lat, $lon), new Coordinate($lat + 0.1, $lon + 0.1)],
        );
    }

    private function forecast(): WeatherForecast
    {
        return new WeatherForecast(
            icon: '10d',
            description: 'Rain',
            tempMin: 9.0,
            tempMax: 18.0,
            windSpeed: 22.0,
            windDirection: 'S',
            precipitationProbability: 70,
            humidity: 80,
            comfortIndex: 60,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
        );
    }

    private function cacheKey(string $locale, float $lat, float $lon, int $i): string
    {
        return \sprintf('weather.%s.%s.%s.%d', $locale, round($lat, 2), round($lon, 2), $i);
    }

    /**
     * @param list<Stage> $stages
     */
    private function createHandler(array $stages, WeatherProviderInterface $provider, ArrayAdapter $cache): FetchWeatherHandler
    {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        $messageBus = $this->createStub(MessageBusInterface::class);
        // dispatch() returns the final Envelope class, which cannot be doubled.
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = new FetchWeatherHandler(
            $computationTracker,
            $publisher,
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            $provider,
            $cache,
            $messageBus,
        );
        $handler->setCompletionGate(new TripCompletionGate($computationTracker, $publisher, $messageBus));

        return $handler;
    }

    #[Test]
    public function skipsNullForecastsWithoutCachingAndStillProcessesOthers(): void
    {
        $stage0 = $this->stage(1, 47.0, -2.0); // provider returns null for this one
        $stage1 = $this->stage(2, 48.0, 3.0);  // provider returns a valid forecast

        $provider = $this->createStub(WeatherProviderInterface::class);
        $provider->method('fetchForecasts')->willReturn([null, $this->forecast()]);

        $cache = new ArrayAdapter();

        ($this->createHandler([$stage0, $stage1], $provider, $cache))(new FetchWeather('trip-1'));

        self::assertNull($stage0->weather, 'a null forecast leaves the stage weather absent');
        self::assertInstanceOf(WeatherForecast::class, $stage1->weather, 'other forecasts in the same batch still apply');

        self::assertFalse(
            $cache->getItem($this->cacheKey('en', 47.0, -2.0, 0))->isHit(),
            'a null forecast is never cached',
        );
        self::assertTrue(
            $cache->getItem($this->cacheKey('en', 48.0, 3.0, 1))->isHit(),
            'a valid forecast is cached',
        );
    }
}
