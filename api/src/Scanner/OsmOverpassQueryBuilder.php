<?php

declare(strict_types=1);

namespace App\Scanner;

use App\ApiResource\TripRequest;
use App\ApiResource\Model\Coordinate;

final readonly class OsmOverpassQueryBuilder implements QueryBuilderInterface
{
    private const int AROUND_RADIUS_METERS = 2000;

    private const int WAYS_RADIUS_METERS = 100;

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildPoiQuery(array $decimatedPoints): string
    {
        $polyline = $this->buildPolyline($decimatedPoints);

        return \sprintf(
            '[out:json][timeout:15];(nwr["amenity"~"^(restaurant|cafe|bar|pharmacy|fast_food|marketplace)$"](around:%d,%s);nwr["shop"~"^(convenience|supermarket|bakery|butcher|pastry|deli|greengrocer|general|farm)$"](around:%d,%s);nwr["tourism"~"^(viewpoint|attraction)$"](around:%d,%s););out center 200;',
            self::AROUND_RADIUS_METERS,
            $polyline,
            self::AROUND_RADIUS_METERS,
            $polyline,
            self::AROUND_RADIUS_METERS,
            $polyline,
        );
    }

    /**
     * @param array<int, Coordinate> $endPoints
     * @param list<string>           $enabledTypes OSM tourism types to include (default: all 7)
     */
    public function buildAccommodationQuery(array $endPoints, int $radiusMeters = self::DEFAULT_ACCOMMODATION_RADIUS_METERS, array $enabledTypes = TripRequest::ALL_ACCOMMODATION_TYPES): string
    {
        $typesPattern = implode('|', array_map(preg_quote(...), $enabledTypes, array_fill(0, \count($enabledTypes), '/')));

        $filters = '';
        foreach ($endPoints as $point) {
            $filters .= \sprintf(
                'nwr["tourism"~"^(%s)$"](around:%d,%F,%F);',
                $typesPattern,
                $radiusMeters,
                $point->lat,
                $point->lon,
            );
        }

        return \sprintf('[out:json][timeout:15];(%s);out center 100;', $filters);
    }

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildBikeShopQuery(array $decimatedPoints): string
    {
        $polyline = $this->buildPolyline($decimatedPoints);

        return \sprintf(
            '[out:json][timeout:15];(nwr["shop"="bicycle"](around:%1$d,%2$s);nwr["service:bicycle:repair"="yes"](around:%1$d,%2$s););out center tags 50;',
            self::AROUND_RADIUS_METERS,
            $polyline,
        );
    }

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildWaysQuery(array $decimatedPoints): string
    {
        $polyline = $this->buildPolyline($decimatedPoints);

        return \sprintf(
            '[out:json][timeout:25];way["highway"~"^(primary|secondary|tertiary|unclassified|residential|living_street|service|track|path|cycleway|footway|bridleway)$"](around:%d,%s);out tags geom qt;',
            self::WAYS_RADIUS_METERS,
            $polyline,
        );
    }

    /**
     * Queries cemeteries as a proxy for potable water.
     *
     * In France, cemeteries are legally required to provide a water tap on-site,
     * making them a reliable indicator of accessible water along a route.
     * This heuristic is most accurate for French itineraries.
     *
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildCemeteryQuery(array $decimatedPoints): string
    {
        $polyline = $this->buildPolyline($decimatedPoints);

        return \sprintf(
            '[out:json][timeout:15];(nwr["landuse"="cemetery"](around:%d,%s);nwr["amenity"="grave_yard"](around:%d,%s););out center 500;',
            self::AROUND_RADIUS_METERS,
            $polyline,
            self::AROUND_RADIUS_METERS,
            $polyline,
        );
    }

    /**
     * @param list<Coordinate> $endPoints
     */
    public function buildRailwayStationQuery(array $endPoints, int $radiusMeters = 10000): string
    {
        $polyline = $this->buildPolyline($endPoints);

        return \sprintf(
            '[out:json][timeout:15];nwr["railway"="station"]["usage"!="tourism"](around:%d,%s);out center tags 500;',
            $radiusMeters,
            $polyline,
        );
    }

    /**
     * @param list<list<Coordinate>> $stageGeometries
     *
     * Note: 'out center tags 100' caps the result set globally across all stages.
     * Acceptable for typical bikepacking trips (≤7 stages, sparse cultural POIs).
     */
    public function buildBatchCulturalPoiQuery(array $stageGeometries, int $radiusMeters = 500): string
    {
        $allPoints = array_merge(...$stageGeometries);
        $polyline = $this->buildPolyline($allPoints);

        return \sprintf(
            '[out:json][timeout:15];(nwr["tourism"="museum"](around:%1$d,%2$s);nwr["tourism"="attraction"](around:%1$d,%2$s);nwr["tourism"="viewpoint"](around:%1$d,%2$s);nwr["historic"~"^(castle|monument|memorial|ruins|archaeological_site|church|cathedral|abbey|fort)$"](around:%1$d,%2$s););out center tags 100;',
            $radiusMeters,
            $polyline,
        );
    }

    /** @param list<Coordinate> $points */
    private function buildPolyline(array $points): string
    {
        $parts = [];
        foreach ($points as $point) {
            $parts[] = \sprintf('%F,%F', $point->lat, $point->lon);
        }

        return implode(',', $parts);
    }
}
