<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use App\Weather\CachingWeatherProvider;
use App\Weather\RawForecast;
use App\Weather\RawHourlySlot;
use App\Weather\WeatherProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachingWeatherProviderTest extends TestCase
{
    private const array PARIS = ['lat' => 48.8566, 'lon' => 2.3522, 'date' => '2026-09-04'];

    private const array LYON = ['lat' => 45.764, 'lon' => 4.8357, 'date' => '2026-09-05'];

    #[Test]
    public function fetchesOnlyTheDaysItHasNotSeenAndKeepsTheResultAligned(): void
    {
        $cache = new ArrayAdapter();
        $calls = [];
        $inner = $this->inner(function (array $locations) use (&$calls): array {
            $calls[] = $locations;

            return array_map(fn (array $location): RawForecast => $this->day($location['date'], (float) \count($calls)), $locations);
        });
        $provider = new CachingWeatherProvider($inner, $cache, new NullLogger());

        $provider->fetchDayForecasts([self::PARIS]);

        $forecasts = $provider->fetchDayForecasts([self::LYON, self::PARIS]);

        self::assertSame([[self::PARIS], [self::LYON]], $calls, 'Paris comes from the cache the second time');
        self::assertNotNull($forecasts[0]);
        self::assertNotNull($forecasts[1]);
        self::assertSame(2.0, $forecasts[0]->slots[0]->temp, 'Lyon, fetched by the second call');
        self::assertSame(1.0, $forecasts[1]->slots[0]->temp, 'Paris, as cached by the first call');
        self::assertSame('2026-09-04T12:00:00+02:00', $forecasts[1]->slots[0]->time->format(\DateTimeInterface::ATOM));
        self::assertSame('Europe/Paris', $forecasts[1]->timezone->getName());
    }

    #[Test]
    public function keysTheDayOnTheRoundedLocationAndTheDate(): void
    {
        $cache = new ArrayAdapter();
        $provider = new CachingWeatherProvider($this->inner(fn (array $locations): array => [$this->day('2026-09-04', 1.0)]), $cache, new NullLogger());

        $provider->fetchDayForecasts([self::PARIS]);

        self::assertTrue($cache->getItem('weather2.48.86.2.35.2026-09-04')->isHit());
    }

    #[Test]
    public function neverCachesADayWithoutForecast(): void
    {
        $cache = new ArrayAdapter();
        $provider = new CachingWeatherProvider($this->inner(static fn (array $locations): array => [null]), $cache, new NullLogger());

        self::assertSame([null], $provider->fetchDayForecasts([self::PARIS]));
        self::assertFalse($cache->getItem('weather2.48.86.2.35.2026-09-04')->isHit());
    }

    #[Test]
    public function aFailedFetchStillReturnsTheCachedDays(): void
    {
        $cache = new ArrayAdapter();
        new CachingWeatherProvider($this->inner(fn (array $locations): array => [$this->day('2026-09-04', 1.0)]), $cache, new NullLogger())
            ->fetchDayForecasts([self::PARIS]);

        $failing = new CachingWeatherProvider($this->inner(static fn (array $locations): never => throw new \RuntimeException('down')), $cache, new NullLogger());
        $forecasts = $failing->fetchDayForecasts([self::PARIS, self::LYON]);

        self::assertNotNull($forecasts[0]);
        self::assertNull($forecasts[1]);
    }

    /**
     * @param \Closure(list<array{lat: float, lon: float, date: string}>): list<?RawForecast> $fetch
     */
    private function inner(\Closure $fetch): WeatherProviderInterface
    {
        return new readonly class ($fetch) implements WeatherProviderInterface {
            /**
             * @param \Closure(list<array{lat: float, lon: float, date: string}>): list<?RawForecast> $fetch
             */
            public function __construct(private \Closure $fetch)
            {
            }

            public function fetchDayForecasts(array $locations): array
            {
                return ($this->fetch)($locations);
            }
        };
    }

    private function day(string $date, float $temp): RawForecast
    {
        $tz = new \DateTimeZone('Europe/Paris');

        return new RawForecast($tz, [new RawHourlySlot(
            time: new \DateTimeImmutable($date.'T12:00', $tz),
            temp: $temp,
            apparentTemp: $temp,
            precipitationMm: 0.0,
            precipitationProbability: 0,
            windSpeed: 10.0,
            windGusts: 20.0,
            windDirectionDeg: 180,
            humidity: 60,
            uvIndex: 4.0,
            weatherCode: 1,
        )]);
    }
}
