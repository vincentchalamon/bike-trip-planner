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
     * (exact), null otherwise (the caller falls back to a heuristic). `tags` carries
     * the flux keys the provisioner preserved, flattened to the OSM-tag contract
     * (`opening_hours`, `website`, `phone`, …).
     *
     * @param list<array{lat: float, lon: float}> $points
     * @param list<string>                        $categories
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, capacity: ?int, price: ?float, description: ?string, tags: array<string, string>}>
     */
    public function findNear(array $points, int $radiusMeters, array $categories): array;
}
