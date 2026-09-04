<?php

declare(strict_types=1);

namespace App\Weather;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenMeteoProvider implements WeatherProviderInterface
{
    private const string HOURLY_VARS = 'temperature_2m,apparent_temperature,precipitation,precipitation_probability,weather_code,wind_speed_10m,wind_gusts_10m,wind_direction_10m,relative_humidity_2m,uv_index';

    public function __construct(
        #[Autowire(service: 'open_meteo.client')]
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetchForecast(float $lat, float $lon, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): ?RawForecast
    {
        $response = $this->httpClient->request('GET', '/v1/forecast', [
            'query' => [
                'latitude' => $lat,
                'longitude' => $lon,
                'hourly' => self::HOURLY_VARS,
                'timezone' => 'auto',
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        return $this->parseForecast($data);
    }

    public function fetchForecasts(array $locations, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array
    {
        if ([] === $locations) {
            return [];
        }

        // Single location: delegate — the API response format differs (object vs array).
        if (1 === \count($locations)) {
            return [$this->fetchForecast($locations[0]['lat'], $locations[0]['lon'], $startDate, $endDate)];
        }

        $latitudes = implode(',', array_map(static fn (array $loc): string => (string) $loc['lat'], $locations));
        $longitudes = implode(',', array_map(static fn (array $loc): string => (string) $loc['lon'], $locations));

        $response = $this->httpClient->request('GET', '/v1/forecast', [
            'query' => [
                'latitude' => $latitudes,
                'longitude' => $longitudes,
                'hourly' => self::HOURLY_VARS,
                'timezone' => 'auto',
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        ]);

        /** @var list<array<string, mixed>> $dataList */
        $dataList = $response->toArray();

        return array_map($this->parseForecast(...), $dataList);
    }

    /**
     * Builds a RawForecast from one open-meteo response block, or null when the
     * hourly block is absent — so a 200 response with an empty body yields no
     * weather rather than fabricated readings (Tier 3 "no fake data").
     *
     * @param array<string, mixed> $data
     */
    private function parseForecast(array $data): ?RawForecast
    {
        $hourly = $data['hourly'] ?? null;
        if (!\is_array($hourly) || !isset($hourly['time']) || !\is_array($hourly['time'])) {
            return null;
        }

        $tzName = \is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC';
        try {
            $timezone = new \DateTimeZone($tzName);
        } catch (\Exception) {
            $timezone = new \DateTimeZone('UTC');
        }

        $times = array_values($hourly['time']);
        $temps = $this->column($hourly, 'temperature_2m');
        $apparent = $this->column($hourly, 'apparent_temperature');
        $precip = $this->column($hourly, 'precipitation');
        $precipProb = $this->column($hourly, 'precipitation_probability');
        $code = $this->column($hourly, 'weather_code');
        $windSpeed = $this->column($hourly, 'wind_speed_10m');
        $windGusts = $this->column($hourly, 'wind_gusts_10m');
        $windDir = $this->column($hourly, 'wind_direction_10m');
        $humidity = $this->column($hourly, 'relative_humidity_2m');
        $uv = $this->column($hourly, 'uv_index');

        $slots = [];
        foreach ($times as $i => $time) {
            if (!\is_string($time) || !isset($temps[$i])) {
                continue;
            }

            try {
                $dt = new \DateTimeImmutable($time, $timezone);
            } catch (\Exception) {
                continue;
            }

            $temp = $this->floatAt($temps, $i, 0.0);
            $slots[] = new RawHourlySlot(
                time: $dt,
                temp: $temp,
                apparentTemp: $this->floatAt($apparent, $i, $temp),
                precipitationMm: $this->floatAt($precip, $i, 0.0),
                precipitationProbability: $this->intAt($precipProb, $i, 0),
                windSpeed: $this->floatAt($windSpeed, $i, 0.0),
                windGusts: $this->floatAt($windGusts, $i, 0.0),
                windDirectionDeg: $this->intAt($windDir, $i, 0),
                humidity: $this->intAt($humidity, $i, 50),
                uvIndex: $this->floatAt($uv, $i, 0.0),
                weatherCode: $this->intAt($code, $i, 0),
            );
        }

        if ([] === $slots) {
            return null;
        }

        return new RawForecast($timezone, $slots);
    }

    /**
     * @param array<mixed, mixed> $hourly
     *
     * @return array<int, mixed>
     */
    private function column(array $hourly, string $key): array
    {
        $col = $hourly[$key] ?? null;

        return \is_array($col) ? array_values($col) : [];
    }

    /**
     * @param array<int, mixed> $col
     */
    private function floatAt(array $col, int $i, float $default): float
    {
        $v = $col[$i] ?? null;

        return is_numeric($v) ? (float) $v : $default;
    }

    /**
     * @param array<int, mixed> $col
     */
    private function intAt(array $col, int $i, int $default): int
    {
        $v = $col[$i] ?? null;

        return is_numeric($v) ? (int) $v : $default;
    }
}
