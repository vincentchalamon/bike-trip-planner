<?php

declare(strict_types=1);

namespace App\Tourism;

interface AccommodationRepositoryInterface
{
    /**
     * DataTourisme accommodations of the given categories within $radiusMeters of
     * any point, grouped by the end point they belong to (nearest first within each
     * group) and capped per end point, so a dense end point cannot starve a sparse
     * one. `price` is the structured offer price when DataTourisme provided one
     * (exact), null otherwise (the caller falls back to a heuristic).
     * `website` / `phone` / `openingHours` / `wikidata` are the flux columns added
     * by #872, `imageUrl` / `wikipediaUrl` the ones the provisioner's Wikidata pass
     * fills. `tags` still carries the flux keys the provisioner preserved, flattened
     * to the OSM-tag contract (`opening_hours`, `website`, `booking_url`, …): it is
     * both the fallback for rows imported before the columns existed and the only
     * home of the keys with no column (`booking_url`, `email`, `image_url`).
     *
     * @param list<array{lat: float, lon: float}> $points
     * @param list<string>                        $categories
     *
     * `name` is non-nullable by construction since #884: completeness is decided at import
     * time and a per-category CHECK enforces it, and this query excludes the one exempt
     * category (`shelter`). A row without a name therefore cannot exist among these results.
     *
     * @return list<array{name: string, category: string, lat: float, lon: float, capacity: ?int, price: ?float, description: ?string, website: ?string, phone: ?string, openingHours: ?string, wikidata: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}>
     */
    public function findNear(array $points, int $radiusMeters, array $categories): array;
}
