<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use App\Weather\OpenMeteoProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoProviderTest extends TestCase
{
    private const string START = '2026-09-04';

    private const string END = '2026-09-04';

    private function provider(MockHttpClient $client): OpenMeteoProvider
    {
        return new OpenMeteoProvider($client);
    }

    /**
     * @param array<string, mixed> $hourly
     */
    private function hourlyResponse(array $hourly, string $tz = 'Europe/Paris'): MockResponse
    {
        return new MockResponse((string) json_encode(['timezone' => $tz, 'hourly' => $hourly]));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function fullHourly(): array
    {
        return [
            'time' => ['2026-09-04T08:00', '2026-09-04T09:00', '2026-09-04T10:00'],
            'temperature_2m' => [12.0, 14.0, 16.0],
            'apparent_temperature' => [10.0, 12.5, 14.0],
            'precipitation' => [0.0, 0.5, 1.2],
            'precipitation_probability' => [10, 40, 70],
            'weather_code' => [3, 61, 61],
            'wind_speed_10m' => [12.0, 18.0, 22.0],
            'wind_gusts_10m' => [20.0, 30.0, 45.0],
            'wind_direction_10m' => [180, 200, 210],
            'relative_humidity_2m' => [70, 75, 80],
            'uv_index' => [1.0, 2.0, 3.0],
        ];
    }

    private function startDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::START);
    }

    private function endDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::END);
    }

    #[Test]
    public function parsesRawHourlySeriesWithTimezone(): void
    {
        $raw = $this->provider(new MockHttpClient($this->hourlyResponse($this->fullHourly())))
            ->fetchForecast(48.5, 2.3, $this->startDate(), $this->endDate());

        self::assertNotNull($raw);
        self::assertSame('Europe/Paris', $raw->timezone->getName());
        self::assertCount(3, $raw->slots);

        $slot = $raw->slots[2];
        self::assertSame(16.0, $slot->temp);
        self::assertSame(14.0, $slot->apparentTemp);
        self::assertSame(1.2, $slot->precipitationMm);
        self::assertSame(70, $slot->precipitationProbability);
        self::assertSame(22.0, $slot->windSpeed);
        self::assertSame(45.0, $slot->windGusts);
        self::assertSame(210, $slot->windDirectionDeg);
        self::assertSame(61, $slot->weatherCode);
        self::assertSame('2026-09-04', $slot->time->format('Y-m-d'));
        self::assertSame(10, (int) $slot->time->format('G'));
    }

    #[Test]
    public function returnsNullWhenTheResponseHasNoHourlyBlock(): void
    {
        self::assertNull(
            $this->provider(new MockHttpClient(new MockResponse((string) json_encode([]))))
                ->fetchForecast(48.5, 2.3, $this->startDate(), $this->endDate()),
        );
    }

    #[Test]
    public function fetchForecastsReturnsEmptyForNoLocations(): void
    {
        self::assertSame(
            [],
            $this->provider(new MockHttpClient())->fetchForecasts([], $this->startDate(), $this->endDate()),
        );
    }

    #[Test]
    public function fetchForecastsAlignsNullsToLocationsForAMixedBatch(): void
    {
        // Multi-location batch: open-meteo returns a list; the second location has
        // no usable data, so the result is [raw, null] aligned to the input.
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            ['timezone' => 'Europe/Paris', 'hourly' => $this->fullHourly()],
            ['timezone' => 'Europe/Paris', 'hourly' => []],
        ])));

        $forecasts = $this->provider($client)->fetchForecasts([
            ['lat' => 48.5, 'lon' => 2.3],
            ['lat' => 45.0, 'lon' => 5.0],
        ], $this->startDate(), $this->endDate());

        self::assertCount(2, $forecasts);
        self::assertNotNull($forecasts[0]);
        self::assertCount(3, $forecasts[0]->slots);
        self::assertNull($forecasts[1]);
    }
}
