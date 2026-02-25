<?php

declare(strict_types=1);

namespace App\GpxWriter;

use App\ApiResource\Model\Coordinate;

interface GpxWriterInterface
{
    /**
     * Generates a valid GPX 1.1 XML string from a list of coordinates.
     *
     * @param list<Coordinate> $points
     */
    public function generate(array $points, string $trackName = ''): string;
}
