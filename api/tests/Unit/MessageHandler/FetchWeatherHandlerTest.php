<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Tests\Unit\AlertMessageTestTrait;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\RiderTimeEstimator;
use App\Message\FetchWeather;
use App\Mercure\MercureEventType;
use App\MessageHandler\FetchWeatherHandler;
use App\MessageHandler\TripHandlerContext;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use App\Weather\CachingWeatherProvider;
use App\Weather\RawForecast;
use App\Weather\RawHourlySlot;
use App\Weather\WeatherForecastDeriver;
use App\Weather\WeatherForecastSerializer;
use App\Weather\WeatherProviderInterface;
use App\Weather\WmoWeatherMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FetchWeatherHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

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

    /**
     * A full 24-hour raw series for today (UTC), so any riding window is covered.
     */
    private function rawForToday(): RawForecast
    {
        $tz = new \DateTimeZone('UTC');
        $base = new \DateTimeImmutable('today', $tz);
        $slots = [];
        for ($h = 0; $h < 24; ++$h) {
            $slots[] = new RawHourlySlot(
                time: $base->setTime($h, 0),
                temp: 15.0,
                apparentTemp: 14.0,
                precipitationMm: 0.2,
                precipitationProbability: 40,
                windSpeed: 20.0,
                windGusts: 30.0,
                windDirectionDeg: 200,
                humidity: 75,
                uvIndex: 3.0,
                weatherCode: 61,
            );
        }

        return new RawForecast($tz, $slots);
    }

    private function cacheKey(float $lat, float $lon): string
    {
        $date = new \DateTimeImmutable('today', new \DateTimeZone('UTC'))->format('Y-m-d');

        return \sprintf('weather2.%s.%s.%s', round($lat, 2), round($lon, 2), $date);
    }

    /**
     * @param list<Stage> $stages
     */
    private function createHandler(
        array $stages,
        WeatherProviderInterface $provider,
        ArrayAdapter $cache,
        ?TripUpdatePublisherInterface $publisher = null,
    ): FetchWeatherHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'settled' => 0, 'total' => 1]);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $stageStore = $this->createStub(TripStageStoreInterface::class);
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $stageStore->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        $messageBus = $this->createStub(MessageBusInterface::class);
        // dispatch() returns the final Envelope class, which cannot be doubled.
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new FetchWeatherHandler(
            new TripHandlerContext($computationTracker, $publisher ?? $this->createStub(TripUpdatePublisherInterface::class), $this->createStub(TripGenerationTrackerInterface::class), new NullLogger(), $tripStateManager, $stageStore, $messageBus, $this->createAlertRenderer()),
            new CachingWeatherProvider($provider, $cache, new NullLogger()),
            new RiderTimeEstimator(),
            new WeatherForecastDeriver(new WmoWeatherMapper($translator)),
            new WeatherForecastSerializer(),
        );
    }

    #[Test]
    public function skipsNullForecastsWithoutCachingAndStillProcessesOthers(): void
    {
        $stage0 = $this->stage(1, 47.0, -2.0); // provider returns null for this one
        $stage1 = $this->stage(1, 48.0, 3.0);  // provider returns a valid raw series

        $provider = $this->createStub(WeatherProviderInterface::class);
        $provider->method('fetchDayForecasts')->willReturn([null, $this->rawForToday()]);

        $cache = new ArrayAdapter();

        ($this->createHandler([$stage0, $stage1], $provider, $cache))(new FetchWeather('trip-1'));

        self::assertNull($stage0->weather, 'a null forecast leaves the stage weather absent');
        self::assertInstanceOf(WeatherForecast::class, $stage1->weather, 'other forecasts in the same batch still apply');
        self::assertNotEmpty($stage1->weather->hourly, 'the riding-window hourly series is populated');

        self::assertFalse(
            $cache->getItem($this->cacheKey(47.0, -2.0))->isHit(),
            'a null forecast is never cached',
        );
        self::assertTrue(
            $cache->getItem($this->cacheKey(48.0, 3.0))->isHit(),
            'a valid raw series is cached location-wide',
        );
    }

    #[Test]
    public function reDerivesFromCacheWithoutRefetching(): void
    {
        // The raw day is cached location-wide, so a second run (e.g. after the rider
        // tweaks pace) re-derives without hitting the provider again.
        $stage = $this->stage(1, 48.0, 3.0);
        $cache = new ArrayAdapter();

        $provider = $this->createStub(WeatherProviderInterface::class);
        $provider->method('fetchDayForecasts')->willReturn([$this->rawForToday()]);

        ($this->createHandler([$stage], $provider, $cache))(new FetchWeather('trip-1'));
        self::assertInstanceOf(WeatherForecast::class, $stage->weather);

        // Second run with a provider that would throw if called.
        $stage2 = $this->stage(1, 48.0, 3.0);
        $failing = $this->createStub(WeatherProviderInterface::class);
        $failing->method('fetchDayForecasts')->willThrowException(new \RuntimeException('should not be called'));

        ($this->createHandler([$stage2], $failing, $cache))(new FetchWeather('trip-1'));
        self::assertInstanceOf(WeatherForecast::class, $stage2->weather, 'weather derived from the cached raw series');
    }

    #[Test]
    public function publishesEachForecastAddressedByStageId(): void
    {
        // A structural edit renumbers the stages while the forecast is being fetched, so the
        // payload must name the stage by its stable identifier, never by its day number.
        $withWeather = $this->stage(1, 48.0, 3.0);
        $withoutWeather = $this->stage(2, 47.0, -2.0);

        $provider = $this->createStub(WeatherProviderInterface::class);
        $provider->method('fetchDayForecasts')->willReturn([$this->rawForToday(), null]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WEATHER_FETCHED,
                $this->callback(static function (array $data) use ($withWeather, $withoutWeather): bool {
                    self::assertSame(['stages'], array_keys($data));
                    self::assertIsArray($data['stages']);
                    self::assertCount(2, $data['stages']);
                    [$first, $second] = $data['stages'];
                    self::assertIsArray($first);
                    self::assertIsArray($second);
                    self::assertSame(['stageId', 'weather'], array_keys($first));
                    self::assertSame($withWeather->id, $first['stageId']);
                    self::assertIsArray($first['weather']);
                    self::assertSame(['stageId' => $withoutWeather->id, 'weather' => null], $second);

                    return true;
                }),
            );

        ($this->createHandler([$withWeather, $withoutWeather], $provider, new ArrayAdapter(), $publisher))(new FetchWeather('trip-1'));
    }
}
