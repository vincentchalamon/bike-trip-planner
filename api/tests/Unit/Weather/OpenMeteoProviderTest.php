<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use App\Weather\OpenMeteoProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpenMeteoProviderTest extends TestCase
{
    private function provider(MockHttpClient $client): OpenMeteoProvider
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new OpenMeteoProvider($client, $translator);
    }

    /**
     * @param array<string, list<float|int>> $daily
     */
    private function dailyResponse(array $daily): MockResponse
    {
        return new MockResponse((string) json_encode(['daily' => $daily]));
    }

    /**
     * @return array<string, list<float|int>>
     */
    private function fullDaily(): array
    {
        return [
            'weather_code' => [61],
            'temperature_2m_max' => [18.5],
            'temperature_2m_min' => [9.0],
            'precipitation_probability_max' => [70],
            'wind_speed_10m_max' => [22.0],
            'wind_direction_10m_dominant' => [180],
            'relative_humidity_2m_mean' => [80],
        ];
    }

    #[Test]
    public function parsesAValidForecast(): void
    {
        $forecast = $this->provider(new MockHttpClient($this->dailyResponse($this->fullDaily())))
            ->fetchForecast(48.5, 2.3);

        self::assertNotNull($forecast);
        self::assertSame(18.5, $forecast->tempMax);
        self::assertSame(9.0, $forecast->tempMin);
        self::assertSame(70, $forecast->precipitationProbability);
        self::assertSame('weather.rain', $forecast->description);
        self::assertSame('S', $forecast->windDirection);
    }

    #[Test]
    public function returnsNullWhenTheResponseHasNoUsableData(): void
    {
        // A 200 with an empty daily block must yield null, not a fabricated 0 °C
        // clear-sky forecast.
        self::assertNull(
            $this->provider(new MockHttpClient($this->dailyResponse([])))->fetchForecast(48.5, 2.3),
        );
    }

    #[Test]
    public function returnsNullWhenCoreFieldsArePartiallyMissing(): void
    {
        // weather_code present but temperatures missing → not usable.
        self::assertNull(
            $this->provider(new MockHttpClient($this->dailyResponse(['weather_code' => [0]])))->fetchForecast(48.5, 2.3),
        );
    }

    #[Test]
    public function fetchForecastsReturnsEmptyForNoLocations(): void
    {
        self::assertSame([], $this->provider(new MockHttpClient())->fetchForecasts([]));
    }

    #[Test]
    public function fetchForecastsAlignsNullsToLocationsForAMixedBatch(): void
    {
        // Multi-location batch: open-meteo returns a list; the second location has
        // no usable data, so the result is [forecast, null] aligned to the input.
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            ['daily' => $this->fullDaily()],
            ['daily' => []],
        ])));

        $forecasts = $this->provider($client)->fetchForecasts([
            ['lat' => 48.5, 'lon' => 2.3],
            ['lat' => 45.0, 'lon' => 5.0],
        ]);

        self::assertCount(2, $forecasts);
        self::assertNotNull($forecasts[0]);
        self::assertSame(18.5, $forecasts[0]->tempMax);
        self::assertNull($forecasts[1]);
    }
}
