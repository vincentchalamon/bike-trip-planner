<?php

declare(strict_types=1);

namespace App\Weather;

use App\ApiResource\Model\WeatherForecast;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class OpenMeteoProvider
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
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max,wind_direction_10m_dominant',
                'timezone' => 'auto',
                'forecast_days' => 1,
            ],
        ]);

        /** @var array{daily?: array{weather_code?: list<int>, temperature_2m_max?: list<float>, temperature_2m_min?: list<float>, precipitation_probability_max?: list<int>, wind_speed_10m_max?: list<float>, wind_direction_10m_dominant?: list<int>}} $data */
        $data = $response->toArray();

        $daily = $data['daily'] ?? [];
        $weatherCode = ($daily['weather_code'] ?? [0])[0];
        $tempMax = ($daily['temperature_2m_max'] ?? [0.0])[0];
        $tempMin = ($daily['temperature_2m_min'] ?? [0.0])[0];
        $precipProb = ($daily['precipitation_probability_max'] ?? [0])[0];
        $windSpeed = ($daily['wind_speed_10m_max'] ?? [0.0])[0];
        $windDeg = (int) ($daily['wind_direction_10m_dominant'] ?? [0])[0];

        return new WeatherForecast(
            icon: $this->wmoToIcon($weatherCode),
            description: $this->wmoToDescription($weatherCode, $locale),
            tempMin: (float) $tempMin,
            tempMax: (float) $tempMax,
            windSpeed: (float) $windSpeed,
            windDirection: $this->degToDirection($windDeg),
            precipitationProbability: (int) $precipProb,
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
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max,wind_direction_10m_dominant',
                'timezone' => 'auto',
                'forecast_days' => 1,
            ],
        ]);

        /** @var list<array{daily?: array{weather_code?: list<int>, temperature_2m_max?: list<float>, temperature_2m_min?: list<float>, precipitation_probability_max?: list<int>, wind_speed_10m_max?: list<float>, wind_direction_10m_dominant?: list<int>}}> $dataList */
        $dataList = $response->toArray();

        $forecasts = [];
        foreach ($dataList as $data) {
            $daily = $data['daily'] ?? [];
            $weatherCode = ($daily['weather_code'] ?? [0])[0];
            $tempMax = ($daily['temperature_2m_max'] ?? [0.0])[0];
            $tempMin = ($daily['temperature_2m_min'] ?? [0.0])[0];
            $precipProb = ($daily['precipitation_probability_max'] ?? [0])[0];
            $windSpeed = ($daily['wind_speed_10m_max'] ?? [0.0])[0];
            $windDeg = (int) ($daily['wind_direction_10m_dominant'] ?? [0])[0];

            $forecasts[] = new WeatherForecast(
                icon: $this->wmoToIcon($weatherCode),
                description: $this->wmoToDescription($weatherCode, $locale),
                tempMin: (float) $tempMin,
                tempMax: (float) $tempMax,
                windSpeed: (float) $windSpeed,
                windDirection: $this->degToDirection($windDeg),
                precipitationProbability: (int) $precipProb,
            );
        }

        return $forecasts;
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
