<?php

declare(strict_types=1);

namespace App\Scanner;

use App\ApiResource\Model\Coordinate;

final readonly class OsmOverpassQueryBuilder implements QueryBuilderInterface
{
    private const int AROUND_RADIUS_METERS = 2000;

    private const int ACCOMMODATION_RADIUS_METERS = 5000;

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
     * @param list<list<Coordinate>> $stageGeometries
     */
    public function buildBatchPoiQuery(array $stageGeometries): string
    {
        $allPoints = array_merge(...$stageGeometries);
        $polyline = $this->buildPolyline($allPoints);

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
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildAccommodationQuery(array $decimatedPoints): string
    {
        $polyline = $this->buildPolyline($decimatedPoints);

        return \sprintf(
            '[out:json][timeout:15];(nwr["tourism"~"^(camp_site|hostel|hotel|motel|guest_house|chalet|alpine_hut)$"](around:%d,%s););out center 100;',
            self::ACCOMMODATION_RADIUS_METERS,
            $polyline,
        );
    }

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildBikeShopQuery(array $decimatedPoints): string
    {
        $polyline = $this->buildPolyline($decimatedPoints);

        return \sprintf(
            '[out:json][timeout:15];(nwr["shop"="bicycle"](around:%d,%s););out center 50;',
            self::AROUND_RADIUS_METERS,
            $polyline,
        );
    }

    /**
     * @param list<list<Coordinate>> $stageGeometries
     */
    public function buildBatchBikeShopQuery(array $stageGeometries): string
    {
        $allPoints = array_merge(...$stageGeometries);
        $polyline = $this->buildPolyline($allPoints);

        return \sprintf(
            '[out:json][timeout:15];(nwr["shop"="bicycle"](around:%d,%s););out center 50;',
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
