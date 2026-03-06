<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Serializer\Mapper\WaypointMapper;

final readonly class GpxNormalizer extends AbstractStageNormalizer
{
    protected function format(): string
    {
        return 'gpx';
    }

    protected function nameKey(): string
    {
        return 'trackName';
    }

    /**
     * @return array{lat: float, lon: float, name: string, symbol: string, type: string}
     */
    #[\Override]
    protected function buildWaypointEntry(string $name, string $category, float $lat, float $lon): array
    {
        return parent::buildWaypointEntry($name, $category, $lat, $lon) + [
            'symbol' => WaypointMapper::gpxSymbol($category),
        ];
    }
}
