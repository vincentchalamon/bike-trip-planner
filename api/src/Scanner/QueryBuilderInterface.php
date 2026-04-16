<?php

declare(strict_types=1);

namespace App\Scanner;

use App\ApiResource\TripRequest;
use App\ApiResource\Model\Coordinate;

/**
 * Helper class to build queries for fetching POIs, accommodations and bike shops around a route.
 */
interface QueryBuilderInterface
{
    public const int DEFAULT_ACCOMMODATION_RADIUS_METERS = 5000;

    public const int MAX_ACCOMMODATION_RADIUS_METERS = 15000;

    public const int MAX_ACCOMMODATION_RADIUS_KM = self::MAX_ACCOMMODATION_RADIUS_METERS / 1000;

    public const int DEFAULT_ACCOMMODATION_RADIUS_KM = self::DEFAULT_ACCOMMODATION_RADIUS_METERS / 1000;

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildPoiQuery(array $decimatedPoints): string;

    /**
     * @param array<int, Coordinate> $endPoints
     * @param list<string>           $enabledTypes OSM tourism types to include (default: all 7)
     */
    public function buildAccommodationQuery(array $endPoints, int $radiusMeters = self::DEFAULT_ACCOMMODATION_RADIUS_METERS, array $enabledTypes = TripRequest::ALL_ACCOMMODATION_TYPES): string;

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildBikeShopQuery(array $decimatedPoints): string;

    /**
     * Queries road/path ways along the route with surface and highway tags.
     *
     * Returns ways with their tags and geometry, used by terrain analyzers
     * to detect unpaved surfaces and dangerous traffic conditions.
     *
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildWaysQuery(array $decimatedPoints): string;

    /**
     * Queries cemeteries as a proxy for potable water.
     *
     * In France, cemeteries are legally required to provide a water tap on-site,
     * making them a reliable indicator of accessible water along a route.
     * This heuristic is most accurate for French itineraries.
     *
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildCemeteryQuery(array $decimatedPoints): string;

    /**
     * Queries railway stations (excluding tourist railways) around stage endpoints.
     *
     * Used to detect whether a cyclist can reach a train station in case of
     * emergency (mechanical failure, injury, extreme weather).
     *
     * @param list<Coordinate> $endPoints stage start/end points
     */
    public function buildRailwayStationQuery(array $endPoints, int $radiusMeters = 10000): string;

    /**
     * Queries pharmacies, hospitals and clinics along the route.
     *
     * Used by the health-services checker to detect stages with no
     * nearby medical facility within 15 km.
     *
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildHealthServiceQuery(array $decimatedPoints): string;

    /**
     * Build a single Overpass query for cultural POIs along all stages.
     *
     * @param list<list<Coordinate>> $stageGeometries geometry points per stage
     */
    public function buildBatchCulturalPoiQuery(array $stageGeometries, int $radiusMeters = 500): string;

    /**
     * Queries the country (admin_level=2) for a given coordinate using Overpass is_in.
     *
     * Returns relations with admin_level=2 and boundary=administrative that contain the point,
     * allowing border crossing detection by comparing countries at different route positions.
     */
    public function buildCountryQuery(Coordinate $point): string;
}
