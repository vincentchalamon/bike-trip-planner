<?php

declare(strict_types=1);

namespace App\Geo;

use App\ApiResource\Stage;

interface GeometryDistributorInterface
{
    /**
     * Assigns each item to the stage whose endPoint is closest.
     * Output keys match the input $stages keys.
     *
     * @template T of array{lat: float, lon: float, ...}
     *
     * @param list<T>           $items
     * @param array<int, Stage> $stages
     *
     * @return array<int, list<T>>
     */
    public function distributeByEndpoint(array $items, array $stages): array;

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
    public function distributeByGeometry(array $items, array $stages): array;
}
