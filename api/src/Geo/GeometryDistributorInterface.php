<?php

declare(strict_types=1);

namespace App\Geo;

use App\ApiResource\Stage;

interface GeometryDistributorInterface
{
    /**
     * Assigns each item to the stage whose endPoint is closest.
     *
     * @param list<array{lat: float, lon: float}> $items
     * @param list<Stage>                         $stages
     *
     * @return array<int, list<array{lat: float, lon: float}>>
     */
    public function distributeByEndpoint(array $items, array $stages): array;

    /**
     * Assigns each item to the stage whose geometry (all points) is closest.
     *
     * @param list<array{lat: float, lon: float}> $items
     * @param list<Stage>                         $stages
     *
     * @return array<int, list<array{lat: float, lon: float}>>
     */
    public function distributeByGeometry(array $items, array $stages): array;
}
