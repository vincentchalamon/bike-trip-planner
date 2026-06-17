<?php

declare(strict_types=1);

namespace App\Weather;

use App\ApiResource\Model\WeatherForecast;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class OpenMeteoProvider implements WeatherProviderInterface
{
    public function __construct(
        #[Autowire(service: 'open_meteo.client')]
        private HttpClientInterface $httpClient,
        private TranslatorInterface $translator,
        private ComfortIndexCalculator $comfortIndexCalculator = new ComfortIndexCalculator(),
    ) {
    }

    public function fetchForecast(float $lat, float $lon, string $locale = 'en'): ?WeatherForecast
    {
        $response = $this->httpClient->request('GET', '/v1/forecast', [
            'query' => [
                'latitude' => $lat,
                'longitude' => $lon,
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max,wind_direction_10m_dominant,relative_humidity_2m_mean',
                'timezone' => 'auto',
                'forecast_days' => 1,
            ],
        ]);

        /** @var array{daily?: array<string, list<float|int>>} $data */
        $data = $response->toArray();

        return $this->parseForecast($data['daily'] ?? [], $locale);
    }

    /**
     * Fetch forecasts for multiple locations in a single API call.
     *
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<?WeatherForecast>
     */
    public function fetchForecasts(array $locations, string $locale = 'en'): array
    {
        if ([] === $locations) {
            return [];
        }

        // Single location: delegate to existing method (API response format differs)
        if (1 === \count($locations)) {
            return [$this->fetchForecast($locations[0]['lat'], $locations[0]['lon'], $locale)];
        }

        $latitudes = implode(',', array_map(static fn (array $loc): string => (string) $loc['lat'], $locations));
        $longitudes = implode(',', array_map(static fn (array $loc): string => (string) $loc['lon'], $locations));

        $response = $this->httpClient->request('GET', '/v1/forecast', [
            'query' => [
                'latitude' => $latitudes,
                'longitude' => $longitudes,
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max,wind_direction_10m_dominant,relative_humidity_2m_mean',
                'timezone' => 'auto',
                'forecast_days' => 1,
            ],
        ]);

        /** @var list<array{daily?: array<string, list<float|int>>}> $dataList */
        $dataList = $response->toArray();

        return array_map(fn (array $data): ?WeatherForecast => $this->parseForecast($data['daily'] ?? [], $locale), $dataList);
    }

    /**
     * Builds a forecast from one open-meteo `daily` block, or null when the core
     * fields (weather code + both temperatures) are absent — so a 200 response
     * with an empty/partial body yields no weather rather than a fabricated 0 °C
     * clear-sky forecast (ADR — Tier 3 "no fake data").
     *
     * @param array<string, list<float|int>> $daily
     */
    private function parseForecast(array $daily, string $locale): ?WeatherForecast
    {
        if (!isset($daily['weather_code'][0], $daily['temperature_2m_max'][0], $daily['temperature_2m_min'][0])) {
            return null;
        }

        $weatherCode = (int) $daily['weather_code'][0];
        $tempMax = (float) $daily['temperature_2m_max'][0];
        $tempMin = (float) $daily['temperature_2m_min'][0];
        // Secondary fields keep conservative defaults when absent (dry, calm).
        $precipProb = (int) ($daily['precipitation_probability_max'][0] ?? 0);
        $windSpeed = (float) ($daily['wind_speed_10m_max'][0] ?? 0.0);
        $windDeg = (int) ($daily['wind_direction_10m_dominant'][0] ?? 0);
        $humidity = (int) ($daily['relative_humidity_2m_mean'][0] ?? 50);

        return new WeatherForecast(
            icon: $this->wmoToIcon($weatherCode),
            description: $this->wmoToDescription($weatherCode, $locale),
            tempMin: $tempMin,
            tempMax: $tempMax,
            windSpeed: $windSpeed,
            windDirection: $this->degToDirection($windDeg),
            precipitationProbability: $precipProb,
            humidity: $humidity,
            comfortIndex: $this->comfortIndexCalculator->compute($tempMax, $windSpeed, $humidity, $precipProb),
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
        );
    }

    private function wmoToIcon(int $code): string
    {
        return match (true) {
            0 === $code => '01d',
            1 === $code => '02d',
            2 === $code => '03d',
            3 === $code => '04d',
            \in_array($code, [45, 48], true) => '50d',
            $code >= 51 && $code <= 57 => '09d',
            $code >= 61 && $code <= 67 => '10d',
            $code >= 71 && $code <= 77 => '13d',
            $code >= 80 && $code <= 82 => '09d',
            \in_array($code, [85, 86], true) => '13d',
            $code >= 95 && $code <= 99 => '11d',
            default => '01d',
        };
    }

    private function wmoToDescription(int $code, string $locale): string
    {
        $key = match (true) {
            0 === $code => 'weather.clear_sky',
            1 === $code => 'weather.mainly_clear',
            2 === $code => 'weather.partly_cloudy',
            3 === $code => 'weather.overcast',
            \in_array($code, [45, 48], true) => 'weather.fog',
            $code >= 51 && $code <= 57 => 'weather.drizzle',
            $code >= 61 && $code <= 67 => 'weather.rain',
            $code >= 71 && $code <= 77 => 'weather.snow',
            $code >= 80 && $code <= 82 => 'weather.rain_showers',
            \in_array($code, [85, 86], true) => 'weather.snow_showers',
            $code >= 95 && $code <= 99 => 'weather.thunderstorm',
            default => 'weather.unknown',
        };

        return $this->translator->trans($key, [], 'alerts', $locale);
    }

    private function degToDirection(int $deg): string
    {
        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];

        return $directions[(int) round($deg / 45) % 8];
    }
}
