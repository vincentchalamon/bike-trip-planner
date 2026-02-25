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
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildAccommodationQuery(array $decimatedPoints): string;

    /**
     * @param list<Coordinate> $decimatedPoints
     */
    public function buildBikeShopQuery(array $decimatedPoints): string;
}
