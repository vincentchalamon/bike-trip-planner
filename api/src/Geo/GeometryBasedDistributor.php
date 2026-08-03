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
     * Assigns each item to the stage whose endPoint is closest, and to *every*
     * stage tied for that distance.
     * Output keys match the input $stages keys.
     *
     * Stages can share an end point: a rest day copies the previous stage's end
     * point verbatim (RestDayInsertProcessor), and so does an out-and-back. Keeping
     * a single winner starved the later stage of every candidate — the rider slept
     * two nights at the same place but the second night showed no accommodation at
     * all. Two nights at one location legitimately have the same candidates, so the
     * item goes to both rather than being split: splitting would hand each night a
     * disjoint, worse half of the same pool and nudge the rider into changing hotel
     * without moving. Only an exact tie duplicates — the shared coordinate is the
     * same value, so the two distances are the same double.
     *
     * @template T of array{lat: float, lon: float, ...}
     *
     * @param list<T>           $items
     * @param array<int, Stage> $stages
     *
     * @return array<int, list<T>>
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
            $closestStages = [];
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
                    $closestStages = [$i];

                    continue;
                }

                if ($distance === $closestDistance) {
                    $closestStages[] = $i;
                }
            }

            foreach ($closestStages as $i) {
                $result[$i][] = $item;
            }
        }

        return $result;
    }

    /**
     * Assigns each item to the stage whose geometry (all points) is closest.
     *
     * @template T of array{lat: float, lon: float, ...}
     *
     * @param list<T>     $items
     * @param list<Stage> $stages
     *
     * @return array<int, list<T>>
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
