<?php

declare(strict_types=1);

namespace App\Osm;

interface AdminBoundaryRepositoryInterface
{
    /**
     * Resolves the country (admin_level=2 boundary) containing the given point
     * via ST_Covers, returning its name in $locale (falling back to name:en, then
     * the default name), or null when the point lies outside every stored country.
     */
    public function findCountryAt(float $lat, float $lon, string $locale): ?string;
}
