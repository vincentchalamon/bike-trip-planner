<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use App\ApiResource\Model\HourlyWeatherSlot;
use App\ApiResource\Model\WeatherForecast;
use App\Weather\RawForecast;
use App\Weather\RawHourlySlot;
use App\Weather\WeatherForecastDeriver;
use App\Weather\WmoWeatherMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WeatherForecastDeriverTest extends TestCase
{
    private function deriver(): WeatherForecastDeriver
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new WeatherForecastDeriver(new WmoWeatherMapper($translator));
    }

    private function slot(string $localTime, float $temp, float $apparent, float $mm, float $wind, float $gust): RawHourlySlot
    {
        return new RawHourlySlot(
            time: new \DateTimeImmutable($localTime, new \DateTimeZone('Europe/Paris')),
            temp: $temp,
            apparentTemp: $apparent,
            precipitationMm: $mm,
            precipitationProbability: 50,
            windSpeed: $wind,
            windGusts: $gust,
            windDirectionDeg: 0, // wind from the North
            humidity: 70,
            uvIndex: 4.0,
            weatherCode: 61,
        );
    }

    private function raw(): RawForecast
    {
        return new RawForecast(new \DateTimeZone('Europe/Paris'), [
            $this->slot('2026-09-04T07:00', 8.0, 6.0, 0.0, 5.0, 10.0),   // before window
            $this->slot('2026-09-04T08:00', 12.0, 10.0, 1.0, 15.0, 25.0),
            $this->slot('2026-09-04T09:00', 16.0, 14.0, 2.0, 20.0, 35.0),
            $this->slot('2026-09-04T10:00', 20.0, 18.0, 0.5, 18.0, 40.0),
            $this->slot('2026-09-04T13:00', 24.0, 22.0, 9.0, 30.0, 60.0), // after window
        ]);
    }

    #[Test]
    public function derivesHeadlineOverTheRidingWindowOnly(): void
    {
        // Window [8, 10] -> buckets 8..10. Heading North (bearing 0) with wind from
        // the North -> headwind.
        $forecast = $this->deriver()->derive($this->raw(), '2026-09-04', 8.0, 10.0, 0.0, 'en');

        self::assertNotNull($forecast);
        self::assertSame(12.0, $forecast->tempMin);
        self::assertSame(20.0, $forecast->tempMax);
        self::assertSame(10.0, $forecast->apparentTempMin);
        self::assertSame(18.0, $forecast->apparentTempMax);
        self::assertSame(20.0, $forecast->windSpeed, 'max wind over the window');
        self::assertSame(40.0, $forecast->windGusts, 'max gusts over the window');
        self::assertSame(3.5, $forecast->precipitationMm, 'summed precipitation over the window');
        self::assertSame(4, $forecast->uvIndex);
        self::assertCount(3, $forecast->hourly);
        self::assertSame(WeatherForecast::RELATIVE_WIND_HEADWIND, $forecast->relativeWindDirection);
        self::assertSame(WeatherForecast::RELATIVE_WIND_HEADWIND, $forecast->hourly[0]->relativeWindDirection);
    }

    #[Test]
    public function returnsNullWhenNoHourFallsInTheWindow(): void
    {
        // Window [3, 5] -> no matching slot on that date.
        self::assertNull($this->deriver()->derive($this->raw(), '2026-09-04', 3.0, 5.0, 0.0, 'en'));
    }

    #[Test]
    public function returnsNullWhenTheDateIsNotCovered(): void
    {
        self::assertNull($this->deriver()->derive($this->raw(), '2026-09-05', 8.0, 10.0, 0.0, 'en'));
    }

    #[Test]
    public function windowCrossingMidnightPicksUpNextDayHours(): void
    {
        // Late departure: window [22, 26] spans into the next calendar day. The
        // deriver must include the 00:00/01:00 slots and express their hour as
        // 24/25 (monotonic, relative to the stage-day midnight).
        $raw = new RawForecast(new \DateTimeZone('Europe/Paris'), [
            $this->slot('2026-09-04T22:00', 18.0, 16.0, 0.0, 10.0, 20.0),
            $this->slot('2026-09-04T23:00', 16.0, 14.0, 1.0, 12.0, 22.0),
            $this->slot('2026-09-05T00:00', 14.0, 12.0, 2.0, 14.0, 26.0),
            $this->slot('2026-09-05T01:00', 12.0, 9.0, 3.0, 16.0, 30.0),
            $this->slot('2026-09-05T02:00', 11.0, 8.0, 0.0, 8.0, 15.0),
        ]);

        $forecast = $this->deriver()->derive($raw, '2026-09-04', 22.0, 26.0, 0.0, 'en');

        self::assertNotNull($forecast);
        self::assertCount(5, $forecast->hourly, 'includes 22h, 23h and the next-day 00h, 01h, 02h');
        self::assertSame([22, 23, 24, 25, 26], array_map(static fn (HourlyWeatherSlot $h): int => $h->hour, $forecast->hourly));
        self::assertSame(11.0, $forecast->tempMin, 'min over the full window incl. post-midnight');
        self::assertSame(6.0, $forecast->precipitationMm, 'summed across midnight');
    }

    #[Test]
    public function unknownRelativeWindWhenBearingMissing(): void
    {
        $forecast = $this->deriver()->derive($this->raw(), '2026-09-04', 8.0, 10.0, null, 'en');

        self::assertNotNull($forecast);
        self::assertSame(WeatherForecast::RELATIVE_WIND_UNKNOWN, $forecast->relativeWindDirection);
    }
}
