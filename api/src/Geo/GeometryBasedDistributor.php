<?php

declare(strict_types=1);

namespace App\Geo;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;

/**
 * Distributes geolocated items to their nearest stage using Haversine distance.
 *
 * Each item must have at least 'lat' and 'lon' float keys.
 */
final readonly class GeometryBasedDistributor implements GeometryDistributorInterface
{
    public function __construct(
        private GeoDistanceInterface $haversine,
    ) {
    }

    /**
     * Assigns each item to the stage whose endPoint is closest.
     * Output keys match the input $stages keys.
     *
     * @param list<array{lat: float, lon: float}> $items
     * @param array<int, Stage>                   $stages
     *
     * @return array<int, list<array{lat: float, lon: float}>>
     */
    public function distributeByEndpoint(array $items, array $stages): array
    {
        if ([] === $stages) {
            return [];
        }

        $result = [];
        foreach (array_keys($stages) as $i) {
            $result[$i] = [];
        }

        foreach ($items as $item) {
            $closestStage = 0;
            $closestDistance = \PHP_FLOAT_MAX;

            foreach ($stages as $i => $stage) {
                $distance = $this->haversine->inMeters(
                    $item['lat'],
                    $item['lon'],
                    $stage->endPoint->lat,
                    $stage->endPoint->lon,
                );
                if ($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closestStage = $i;
                }
            }

            $result[$closestStage][] = $item;
        }

        return $result;
    }

    /**
     * Assigns each item to the stage whose geometry (all points) is closest.
     *
     * @param list<array{lat: float, lon: float}> $items
     * @param list<Stage>                         $stages
     *
     * @return array<int, list<array{lat: float, lon: float}>>
     */
    public function distributeByGeometry(array $items, array $stages): array
    {
        if ([] === $stages) {
            return [];
        }

        $result = [];
        /** @var array<int, list<array{lat: float, lon: float}>> $stageGeometries */
        $stageGeometries = [];
        foreach ($stages as $i => $stage) {
            $result[$i] = [];
            $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
            $stageGeometries[$i] = array_map(
                static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                $geometry,
            );
        }

        foreach ($items as $item) {
            $closestStage = 0;
            $closestDistance = \PHP_FLOAT_MAX;

            foreach ($stageGeometries as $i => $geometry) {
                foreach ($geometry as $point) {
                    $distance = $this->haversine->inMeters($item['lat'], $item['lon'], $point['lat'], $point['lon']);
                    if ($distance < $closestDistance) {
                        $closestDistance = $distance;
                        $closestStage = $i;
                    }
                }
            }

            $result[$closestStage][] = $item;
        }

        return $result;
    }
}
