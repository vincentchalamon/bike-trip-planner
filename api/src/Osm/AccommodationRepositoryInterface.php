<?php

declare(strict_types=1);

namespace App\Osm;

interface AccommodationRepositoryInterface
{
    /**
     * Accommodations of the given categories within $radiusMeters of any point,
     * grouped by the end point they belong to (nearest first within each group) and
     * capped per end point, so a dense end point cannot starve a sparse one.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @param list<string>                        $categories
     *
     * @return list<array{osmType: ?string, osmId: ?int, name: ?string, category: string, lat: float, lon: float, stars: ?int, capacity: ?int, fee: ?string, website: ?string, wikidata: ?string, openingHours: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}>
     */
    public function findNear(array $points, int $radiusMeters, array $categories): array;
}
