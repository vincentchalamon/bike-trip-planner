<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Serializer\Mapper\WaypointMapper;

/**
 * A {@see \App\ApiResource\Trip} as a single-track GPX: one continuous `<trkseg>`, so that GPS
 * devices and applications display the trip as a single valid tour, and the waypoints of every
 * stage in the global `<wpt>` list.
 */
final readonly class TripGpxNormalizer extends AbstractTripNormalizer
{
    protected function format(): string
    {
        return 'gpx';
    }

    protected function header(TripExport $export): array
    {
        return ['trackName' => $export->name, 'sourceUrl' => $export->sourceUrl];
    }

    /**
     * @return array{lat: float, lon: float, name: string, symbol: string, type: string}
     */
    protected function waypoint(string $name, string $category, float $lat, float $lon): array
    {
        return [
            'lat' => $lat,
            'lon' => $lon,
            'name' => $name,
            'symbol' => WaypointMapper::gpxSymbol($category),
            'type' => $category,
        ];
    }
}
