<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\Weather\WeatherForecastSerializer;

/**
 * The array form of a stage's sub-objects, for the JSONB columns, the `/detail` body and the
 * Mercure payload alike.
 *
 * Each of the three used to carry its own copy, and they drifted: `/detail` served every
 * accommodation field but the address. One copy per shape is the fix, and where the stored
 * shape and the client shape do differ, the difference is a pair of methods here rather than
 * a missing line somewhere else:
 *
 *  - a stored POI keeps `openingHours` and `website`, which the clients are not sent;
 *  - a stored forecast keeps the ten daily scalars as they are, while the clients get
 *    {@see WeatherForecastSerializer}'s shape — rounded wind, apparent temperatures, gusts
 *    and the hourly slots.
 *
 * Accommodations, events and coordinates have one shape everywhere.
 */
final readonly class StageArrayMapper
{
    public function __construct(
        private WeatherForecastSerializer $weatherSerializer,
        private EventArrayMapper $eventMapper,
    ) {
    }

    /** @return array{lat: float, lon: float, ele: float} */
    public function coordinate(Coordinate $coordinate): array
    {
        return ['lat' => $coordinate->lat, 'lon' => $coordinate->lon, 'ele' => $coordinate->ele];
    }

    /** @return array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url: ?string, possibleClosed: bool, distanceToEndPoint: float, source: string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string, phone: ?string, address: ?string, osmType: ?string, osmId: ?int} */
    public function accommodation(Accommodation $accommodation): array
    {
        return [
            'name' => $accommodation->name,
            'type' => $accommodation->type,
            'lat' => $accommodation->lat,
            'lon' => $accommodation->lon,
            'estimatedPriceMin' => $accommodation->estimatedPriceMin,
            'estimatedPriceMax' => $accommodation->estimatedPriceMax,
            'isExactPrice' => $accommodation->isExactPrice,
            'url' => $accommodation->url,
            'possibleClosed' => $accommodation->possibleClosed,
            'distanceToEndPoint' => $accommodation->distanceToEndPoint,
            // Provisioning-time enrichment (Wikidata, ADR-041) and the source attribution
            // badge (issue #870), then the contact block and the OSM identity (issue #873).
            // Each omission used to drop an affordance on every reload and in the shared view.
            'source' => $accommodation->source,
            'description' => $accommodation->description,
            'imageUrl' => $accommodation->imageUrl,
            'wikipediaUrl' => $accommodation->wikipediaUrl,
            'openingHours' => $accommodation->openingHours,
            'phone' => $accommodation->phone,
            'address' => $accommodation->address,
            'osmType' => $accommodation->osmType,
            'osmId' => $accommodation->osmId,
        ];
    }

    /** @param array<string, mixed> $data */
    public function accommodationFromArray(array $data): Accommodation
    {
        /** @var array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url?: ?string, possibleClosed?: bool, distanceToEndPoint?: float, source?: ?string, description?: ?string, imageUrl?: ?string, wikipediaUrl?: ?string, openingHours?: ?string, phone?: ?string, address?: ?string, osmType?: ?string, osmId?: ?int} $row */
        $row = $data;

        return new Accommodation(
            name: $row['name'],
            type: $row['type'],
            lat: $row['lat'],
            lon: $row['lon'],
            estimatedPriceMin: $row['estimatedPriceMin'],
            estimatedPriceMax: $row['estimatedPriceMax'],
            isExactPrice: $row['isExactPrice'],
            url: $row['url'] ?? null,
            possibleClosed: $row['possibleClosed'] ?? false,
            distanceToEndPoint: $row['distanceToEndPoint'] ?? 0.0,
            // Accommodations persisted before issue #870 carry none of the enrichment keys:
            // fall back on the constructor defaults.
            source: $row['source'] ?? 'osm',
            description: $row['description'] ?? null,
            imageUrl: $row['imageUrl'] ?? null,
            wikipediaUrl: $row['wikipediaUrl'] ?? null,
            openingHours: $row['openingHours'] ?? null,
            phone: $row['phone'] ?? null,
            address: $row['address'] ?? null,
            osmType: $row['osmType'] ?? null,
            osmId: $row['osmId'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function event(Event $event): array
    {
        return $this->eventMapper->toArray($event);
    }

    /** @param array<string, mixed> $data */
    public function eventFromArray(array $data): Event
    {
        return $this->eventMapper->fromArray($data);
    }

    /**
     * The stored resupply, in the (repurposed) `pois` column (#1099).
     *
     * @return array<string, mixed>
     */
    public function resupplyForStorage(Resupply $resupply): array
    {
        return $resupply->map($this->poiForStorage(...));
    }

    /**
     * The resupply the clients are sent. Always an object, never null: it is a required field
     * of the stage payload.
     *
     * @return array<string, mixed>
     */
    public function resupplyForClient(?Resupply $resupply): array
    {
        return ($resupply ?? new Resupply())->map($this->poiForClient(...));
    }

    /** @param array<int|string, mixed> $data */
    public function resupplyFromStorage(array $data): Resupply
    {
        // Legacy flat POI list (pre-#1099) or empty: nothing to reconstruct until the trip is
        // re-scanned.
        if (!isset($data['foodAtLunch'], $data['foodAtArrival'])) {
            return new Resupply();
        }

        return new Resupply(
            foodAtLunch: $this->poiListFromStorage($data['foodAtLunch']),
            waterMorning: $this->poiFromStorage($data['waterMorning'] ?? null),
            waterAfternoon: $this->poiFromStorage($data['waterAfternoon'] ?? null),
            foodAtArrival: $this->poiListFromStorage($data['foodAtArrival']),
        );
    }

    /**
     * The ten daily scalars, as they are. Not {@see self::weatherForClient()}: the column has
     * never held the rounded wind, the apparent temperatures, the gusts or the hourly slots.
     *
     * @return array<string, mixed>
     */
    public function weatherForStorage(WeatherForecast $weather): array
    {
        return [
            'icon' => $weather->icon,
            'description' => $weather->description,
            'tempMin' => $weather->tempMin,
            'tempMax' => $weather->tempMax,
            'windSpeed' => $weather->windSpeed,
            'windDirection' => $weather->windDirection,
            'precipitationProbability' => $weather->precipitationProbability,
            'humidity' => $weather->humidity,
            'comfortIndex' => $weather->comfortIndex,
            'relativeWindDirection' => $weather->relativeWindDirection,
        ];
    }

    /** @return array<string, mixed>|null */
    public function weatherForClient(?WeatherForecast $weather): ?array
    {
        return $weather instanceof WeatherForecast ? $this->weatherSerializer->toArray($weather) : null;
    }

    /** @param array<string, mixed> $data */
    public function weatherFromStorage(array $data): WeatherForecast
    {
        /** @var array{icon: string, description: string, tempMin: float, tempMax: float, windSpeed: float, windDirection: string, precipitationProbability: int, humidity: int, comfortIndex: int, relativeWindDirection: string} $row */
        $row = $data;

        return new WeatherForecast(
            icon: $row['icon'],
            description: $row['description'],
            tempMin: $row['tempMin'],
            tempMax: $row['tempMax'],
            windSpeed: $row['windSpeed'],
            windDirection: $row['windDirection'],
            precipitationProbability: $row['precipitationProbability'],
            humidity: $row['humidity'],
            comfortIndex: $row['comfortIndex'],
            relativeWindDirection: $row['relativeWindDirection'],
        );
    }

    /** @return array{name: string, category: string, lat: float, lon: float, distanceFromStart: ?float, osmType: ?string, osmId: ?int, openingHours: ?string, website: ?string} */
    private function poiForStorage(PointOfInterest $poi): array
    {
        return [
            ...$this->poiForClient($poi),
            'openingHours' => $poi->openingHours,
            'website' => $poi->website,
        ];
    }

    /** @return array{name: string, category: string, lat: float, lon: float, distanceFromStart: ?float, osmType: ?string, osmId: ?int} */
    private function poiForClient(PointOfInterest $poi): array
    {
        return [
            'name' => $poi->name,
            'category' => $poi->category,
            'lat' => $poi->lat,
            'lon' => $poi->lon,
            'distanceFromStart' => $poi->distanceFromStart,
            // Without these the OSM link vanishes on reload and in the shared view.
            'osmType' => $poi->osmType,
            'osmId' => $poi->osmId,
        ];
    }

    /** @return list<PointOfInterest> */
    private function poiListFromStorage(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $pois = [];
        foreach ($items as $item) {
            $poi = $this->poiFromStorage($item);
            if ($poi instanceof PointOfInterest) {
                $pois[] = $poi;
            }
        }

        return $pois;
    }

    private function poiFromStorage(mixed $item): ?PointOfInterest
    {
        if (!\is_array($item)) {
            return null;
        }

        /** @var array{name: string, category: string, lat: float, lon: float, distanceFromStart?: ?float, osmType?: ?string, osmId?: ?int, openingHours?: ?string, website?: ?string} $poi */
        $poi = $item;

        return new PointOfInterest(
            name: $poi['name'],
            category: $poi['category'],
            lat: $poi['lat'],
            lon: $poi['lon'],
            distanceFromStart: $poi['distanceFromStart'] ?? null,
            osmType: $poi['osmType'] ?? null,
            osmId: $poi['osmId'] ?? null,
            openingHours: $poi['openingHours'] ?? null,
            website: $poi['website'] ?? null,
        );
    }
}
