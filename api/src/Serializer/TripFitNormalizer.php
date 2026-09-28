<?php

declare(strict_types=1);

namespace App\Serializer;

/**
 * A {@see \App\ApiResource\Trip} as a single FIT course. The {@see FitEncoder} consumes the
 * same `courseName`/`points`/`waypoints` shape as the per-stage FIT export.
 */
final readonly class TripFitNormalizer extends AbstractTripNormalizer
{
    protected function format(): string
    {
        return 'fit';
    }

    protected function header(TripExport $export): array
    {
        return ['courseName' => $export->name];
    }

    /**
     * @return array{lat: float, lon: float, name: string, type: string}
     */
    protected function waypoint(string $name, string $category, float $lat, float $lon): array
    {
        return [
            'lat' => $lat,
            'lon' => $lon,
            'name' => $name,
            'type' => $category,
        ];
    }
}
