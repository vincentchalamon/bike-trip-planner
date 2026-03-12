<?php

declare(strict_types=1);

namespace App\Scanner;

use App\ApiResource\Model\Coordinate;

/**
 * Helper class to build queries for fetching POIs, accommodations and bike shops around a route.
 */
interface QueryBuilderInterface
{
    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildPoiQuery(array $decimatedPoints): string;

    /**
     * Build a single Overpass query for POIs along all stages.
     *
     * @param list<list<Coordinate>> $stageGeometries geometry points per stage
     */
    public function buildBatchPoiQuery(array $stageGeometries): string;

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildAccommodationQuery(array $decimatedPoints): string;

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildBikeShopQuery(array $decimatedPoints): string;

    /**
     * Build a single Overpass query for bike shops along all stages.
     *
     * @param list<list<Coordinate>> $stageGeometries geometry points per stage
     */
    public function buildBatchBikeShopQuery(array $stageGeometries): string;

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
}
