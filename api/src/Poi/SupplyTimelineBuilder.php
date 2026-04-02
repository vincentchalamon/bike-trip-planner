<?php

declare(strict_types=1);

namespace App\Poi;

use App\ApiResource\Model\Coordinate;
use App\Geo\GeoDistanceInterface;

/**
 * Builds a supply timeline by computing distances from route start for
 * food/water POIs, then clustering nearby items into unified markers.
 */
final readonly class SupplyTimelineBuilder
{
    /**
     * Clustering radius in meters: POIs within this distance are grouped into a single marker.
     */
    private const float CLUSTER_RADIUS_METERS = 500.0;

    public function __construct(
        private GeoDistanceInterface $haversine,
    ) {
    }

    /**
     * Builds an array of cumulative distances (in km) along the geometry.
     *
     * @param list<Coordinate> $geometry
     *
     * @return list<float>
     */
    public function buildCumulativeDistances(array $geometry): array
    {
        $cumulative = [0.0];

        for ($i = 1, $count = \count($geometry); $i < $count; ++$i) {
            $prev = $geometry[$i - 1];
            $curr = $geometry[$i];
            $cumulative[] = $cumulative[$i - 1] + $this->haversine->inKilometers($prev->lat, $prev->lon, $curr->lat, $curr->lon);
        }

        return $cumulative;
    }

    /**
     * Finds the index of the closest geometry point to the given coordinates.
     *
     * @param list<Coordinate> $geometry
     */
    public function findNearestGeometryIndex(array $geometry, float $lat, float $lon): int
    {
        $minDist = PHP_FLOAT_MAX;
        $nearest = 0;

        foreach ($geometry as $i => $point) {
            $dist = $this->haversine->inMeters($point->lat, $point->lon, $lat, $lon);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $i;
            }
        }

        return $nearest;
    }

    /**
     * Computes the distance from stage start for each supply point and returns
     * them sorted by distance.
     *
     * @param list<Coordinate>                                                         $geometry
     * @param list<float>                                                              $cumulativeDistances
     * @param list<array{name: string|null, category: string, lat: float, lon: float}> $items
     *
     * @return list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}>
     */
    public function computeDistancesForSupply(array $geometry, array $cumulativeDistances, array $items): array
    {
        if ([] === $items) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            $nearestIndex = $this->findNearestGeometryIndex($geometry, $item['lat'], $item['lon']);
            $result[] = [
                'name' => $item['name'],
                'category' => $item['category'],
                'lat' => $item['lat'],
                'lon' => $item['lon'],
                'distanceFromStart' => round($cumulativeDistances[$nearestIndex], 1),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['distanceFromStart'] <=> $b['distanceFromStart']);

        return $result;
    }

    /**
     * Clusters food and water POIs within CLUSTER_RADIUS_METERS into single markers.
     *
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $foodPois
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $waterPoints
     *
     * @return list<array{
     *     type: 'water'|'food'|'both',
     *     distanceFromStart: float,
     *     lat: float,
     *     lon: float,
     *     water: list<array{name: string|null, lat: float, lon: float, distanceFromStart: float}>,
     *     food: list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}>,
     * }>
     */
    public function clusterSupplyMarkers(array $foodPois, array $waterPoints): array
    {
        /** @var list<array{itemType: 'food'|'water', name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $all */
        $all = [];

        foreach ($foodPois as $p) {
            $all[] = array_merge($p, ['itemType' => 'food']);
        }

        foreach ($waterPoints as $w) {
            $all[] = array_merge($w, ['itemType' => 'water']);
        }

        if ([] === $all) {
            return [];
        }

        usort($all, static fn (array $a, array $b): int => $a['distanceFromStart'] <=> $b['distanceFromStart']);

        /** @var list<list<array{itemType: 'food'|'water', name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}>> $clusters */
        $clusters = [];

        foreach ($all as $item) {
            $placed = false;

            foreach ($clusters as $k => $cluster) {
                $centroid = $cluster[0];
                $dist = $this->haversine->inMeters($centroid['lat'], $centroid['lon'], $item['lat'], $item['lon']);

                if ($dist <= self::CLUSTER_RADIUS_METERS) {
                    $clusters[$k][] = $item;
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $clusters[] = [$item];
            }
        }

        $markers = [];

        foreach ($clusters as $cluster) {
            $hasFood = false;
            $hasWater = false;
            $foodItems = [];
            $waterItems = [];

            foreach ($cluster as $item) {
                if ('food' === $item['itemType']) {
                    $hasFood = true;
                    $foodItems[] = [
                        'name' => $item['name'],
                        'category' => $item['category'],
                        'lat' => $item['lat'],
                        'lon' => $item['lon'],
                        'distanceFromStart' => $item['distanceFromStart'],
                    ];
                } else {
                    $hasWater = true;
                    $waterItems[] = [
                        'name' => $item['name'],
                        'lat' => $item['lat'],
                        'lon' => $item['lon'],
                        'distanceFromStart' => $item['distanceFromStart'],
                    ];
                }
            }

            $type = match (true) {
                $hasFood && $hasWater => 'both',
                $hasFood => 'food',
                default => 'water',
            };

            $first = $cluster[0];

            $markers[] = [
                'type' => $type,
                'distanceFromStart' => $first['distanceFromStart'],
                'lat' => $first['lat'],
                'lon' => $first['lon'],
                'water' => $waterItems,
                'food' => $foodItems,
            ];
        }

        return $markers;
    }
}
