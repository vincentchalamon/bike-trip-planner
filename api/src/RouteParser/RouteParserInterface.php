<?php

declare(strict_types=1);

namespace App\RouteParser;

use App\ApiResource\Model\Coordinate;

interface RouteParserInterface
{
    /**
     * Parses a route string and returns all coordinates.
     *
     * @return list<Coordinate>
     *
     * @throws \RuntimeException When route string is invalid
     */
    public function parse(string $content): array;
}
