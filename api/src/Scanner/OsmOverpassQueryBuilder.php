<?php

declare(strict_types=1);

namespace App\Scanner;

use App\ApiResource\Model\Coordinate;

final readonly class OsmOverpassQueryBuilder implements QueryBuilderInterface
{
    private const int AROUND_RADIUS_METERS = 5000; // 5km around route

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildPoiQuery(array $decimatedPoints): string
    {
        $polyline = $this->buildPolyline($decimatedPoints);

        return \sprintf(
            '[out:json][timeout:25];(nwr["amenity"~"^(restaurant|cafe|bar|supermarket|convenience|bakery|fast_food|marketplace)$"](around:%d,%s);nwr["shop"~"^(convenience|supermarket|bakery)$"](around:%d,%s);nwr["tourism"~"^(viewpoint|attraction)$"](around:%d,%s););out center 200;',
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
            '[out:json][timeout:25];(nwr["tourism"~"^(camp_site|hostel|hotel|motel|guest_house|chalet|alpine_hut)$"](around:%d,%s););out center 100;',
            self::AROUND_RADIUS_METERS,
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
            '[out:json][timeout:25];(nwr["shop"="bicycle"](around:%d,%s););out center 50;',
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
