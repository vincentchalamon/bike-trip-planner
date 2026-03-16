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
    ) {
    }

    public function fetchForecast(float $lat, float $lon, string $locale = 'en'): WeatherForecast
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

        /** @var array{daily?: array{weather_code?: list<int>, temperature_2m_max?: list<float>, temperature_2m_min?: list<float>, precipitation_probability_max?: list<int>, wind_speed_10m_max?: list<float>, wind_direction_10m_dominant?: list<int>, relative_humidity_2m_mean?: list<int>}} $data */
        $data = $response->toArray();

        $daily = $data['daily'] ?? [];
        $weatherCode = ($daily['weather_code'] ?? [0])[0];
        $tempMax = ($daily['temperature_2m_max'] ?? [0.0])[0];
        $tempMin = ($daily['temperature_2m_min'] ?? [0.0])[0];
        $precipProb = ($daily['precipitation_probability_max'] ?? [0])[0];
        $windSpeed = ($daily['wind_speed_10m_max'] ?? [0.0])[0];
        $windDeg = (int) ($daily['wind_direction_10m_dominant'] ?? [0])[0];
        $humidity = (int) ($daily['relative_humidity_2m_mean'] ?? [50])[0];

        return new WeatherForecast(
            icon: $this->wmoToIcon($weatherCode),
            description: $this->wmoToDescription($weatherCode, $locale),
            tempMin: (float) $tempMin,
            tempMax: (float) $tempMax,
            windSpeed: (float) $windSpeed,
            windDirection: $this->degToDirection($windDeg),
            precipitationProbability: (int) $precipProb,
            humidity: $humidity,
            comfortIndex: $this->computeComfortIndex((float) $tempMax, (float) $windSpeed, $humidity, (int) $precipProb),
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
        );
    }

    /**
     * Fetch forecasts for multiple locations in a single API call.
     *
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<WeatherForecast>
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

        /** @var list<array{daily?: array{weather_code?: list<int>, temperature_2m_max?: list<float>, temperature_2m_min?: list<float>, precipitation_probability_max?: list<int>, wind_speed_10m_max?: list<float>, wind_direction_10m_dominant?: list<int>, relative_humidity_2m_mean?: list<int>}}> $dataList */
        $dataList = $response->toArray();

        // @todo #89 SRP: extract parseForecastData() to eliminate duplication with fetchForecast()
        $forecasts = [];
        foreach ($dataList as $data) {
            $daily = $data['daily'] ?? [];
            $weatherCode = ($daily['weather_code'] ?? [0])[0];
            $tempMax = ($daily['temperature_2m_max'] ?? [0.0])[0];
            $tempMin = ($daily['temperature_2m_min'] ?? [0.0])[0];
            $precipProb = ($daily['precipitation_probability_max'] ?? [0])[0];
            $windSpeed = ($daily['wind_speed_10m_max'] ?? [0.0])[0];
            $windDeg = (int) ($daily['wind_direction_10m_dominant'] ?? [0])[0];
            $humidity = (int) ($daily['relative_humidity_2m_mean'] ?? [50])[0];

            $forecasts[] = new WeatherForecast(
                icon: $this->wmoToIcon($weatherCode),
                description: $this->wmoToDescription($weatherCode, $locale),
                tempMin: (float) $tempMin,
                tempMax: (float) $tempMax,
                windSpeed: (float) $windSpeed,
                windDirection: $this->degToDirection($windDeg),
                precipitationProbability: (int) $precipProb,
                humidity: $humidity,
                comfortIndex: $this->computeComfortIndex((float) $tempMax, (float) $windSpeed, $humidity, (int) $precipProb),
                relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
            );
        }

        return $forecasts;
    }

    /**
     * Compute a cyclist comfort index [0–100] from weather parameters.
     *
     * Score starts at 100 and is penalised by:
     *  - High temperature (> 30 °C)  → up to -20 pts
     *  - Cold temperature (< 5 °C)   → up to -20 pts
     *  - High wind speed (> 20 km/h) → up to -30 pts
     *  - High humidity (> 80 %)      → up to -20 pts
     *  - Rain probability (> 20 %)   → up to -30 pts
     */
    private function computeComfortIndex(float $tempMax, float $windSpeed, int $humidity, int $precipProb): int
    {
        $score = 100;

        // Temperature penalties
        if ($tempMax > 30.0) {
            $score -= (int) min(20, ($tempMax - 30.0) * 2);
        } elseif ($tempMax < 5.0) {
            $score -= (int) min(20, (5.0 - $tempMax) * 2);
        }

        // Wind penalty
        if ($windSpeed > 20.0) {
            $score -= (int) min(30, ($windSpeed - 20.0) * 1.5);
        }

        // Humidity penalty
        if ($humidity > 80) {
            $score -= (int) min(20, ($humidity - 80) * 0.5);
        }

        // Precipitation probability penalty
        if ($precipProb > 20) {
            $score -= (int) min(30, ($precipProb - 20) * 0.5);
        }

        return max(0, $score);
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
