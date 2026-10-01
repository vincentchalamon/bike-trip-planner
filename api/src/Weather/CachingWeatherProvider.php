<?php

declare(strict_types=1);

namespace App\Weather;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Keeps each location's forecast day for three hours, so a second run over the same trip (a
 * pace tweak, a recompute) re-derives the riding window without calling the provider again.
 *
 * Keyed on the location rounded to 0.01° (about 1 km) and the local date: the cached series is
 * the raw day, independent of pace and departure time, so every trip passing there that day
 * shares it.
 */
#[AsDecorator(OpenMeteoProvider::class)]
final readonly class CachingWeatherProvider implements WeatherProviderInterface
{
    private const int TTL_SECONDS = 10800;

    public function __construct(
        #[AutowireDecorated]
        private WeatherProviderInterface $inner,
        #[Autowire(service: 'cache.weather')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A failed fetch is not an error for the caller: the days already cached are still
     * returned, and the others come back null, i.e. without a forecast.
     */
    #[\Override]
    public function fetchDayForecasts(array $locations): array
    {
        $forecasts = [];
        /** @var list<array{lat: float, lon: float, date: string}> $misses */
        $misses = [];
        /** @var list<int> $missIndices */
        $missIndices = [];

        foreach ($locations as $i => $location) {
            $item = $this->cache->getItem($this->key($location));
            if ($item->isHit()) {
                /** @var array{tz: string, slots: list<array{t: string, temp: float, app: float, pmm: float, pprob: int, ws: float, wg: float, wd: int, hum: int, uv: float, code: int}>} $cached */
                $cached = $item->get();
                $forecasts[$i] = $this->fromCache($cached);
                continue;
            }

            $forecasts[$i] = null;
            $misses[] = $location;
            $missIndices[] = $i;
        }

        if ([] === $misses) {
            return array_values($forecasts);
        }

        try {
            foreach ($this->inner->fetchDayForecasts($misses) as $k => $forecast) {
                if (!$forecast instanceof RawForecast) {
                    continue;
                }

                $forecasts[$missIndices[$k]] = $forecast;

                $item = $this->cache->getItem($this->key($misses[$k]));
                $item->set($this->toCache($forecast));
                $item->expiresAfter(self::TTL_SECONDS);
                $this->cache->save($item);
            }
        } catch (\Throwable $throwable) {
            $this->logger->warning('Batch weather fetch failed.', ['error' => $throwable->getMessage()]);
        }

        return array_values($forecasts);
    }

    /**
     * @param array{lat: float, lon: float, date: string} $location
     */
    private function key(array $location): string
    {
        return \sprintf('weather2.%s.%s.%s', round($location['lat'], 2), round($location['lon'], 2), $location['date']);
    }

    /**
     * Short keys: a day is 48 hourly slots, and the pool holds one per location and date.
     *
     * @return array{tz: string, slots: list<array<string, mixed>>}
     */
    private function toCache(RawForecast $raw): array
    {
        return [
            'tz' => $raw->timezone->getName(),
            'slots' => array_map(static fn (RawHourlySlot $s): array => [
                't' => $s->time->format(\DateTimeInterface::ATOM),
                'temp' => $s->temp,
                'app' => $s->apparentTemp,
                'pmm' => $s->precipitationMm,
                'pprob' => $s->precipitationProbability,
                'ws' => $s->windSpeed,
                'wg' => $s->windGusts,
                'wd' => $s->windDirectionDeg,
                'hum' => $s->humidity,
                'uv' => $s->uvIndex,
                'code' => $s->weatherCode,
            ], $raw->slots),
        ];
    }

    /**
     * @param array{tz: string, slots: list<array{t: string, temp: float, app: float, pmm: float, pprob: int, ws: float, wg: float, wd: int, hum: int, uv: float, code: int}>} $cached
     */
    private function fromCache(array $cached): RawForecast
    {
        try {
            $tz = new \DateTimeZone($cached['tz']);
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        $slots = [];
        foreach ($cached['slots'] as $s) {
            $slots[] = new RawHourlySlot(
                time: new \DateTimeImmutable($s['t']),
                temp: $s['temp'],
                apparentTemp: $s['app'],
                precipitationMm: $s['pmm'],
                precipitationProbability: $s['pprob'],
                windSpeed: $s['ws'],
                windGusts: $s['wg'],
                windDirectionDeg: $s['wd'],
                humidity: $s['hum'],
                uvIndex: $s['uv'],
                weatherCode: $s['code'],
            );
        }

        return new RawForecast($tz, $slots);
    }
}
