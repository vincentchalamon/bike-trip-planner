<?php

declare(strict_types=1);

namespace App\Osm;

interface AdminBoundaryRepositoryInterface
{
    /**
     * Resolves the country (admin_level=2 boundary) containing the given point
     * via ST_Covers, returning its name in $locale (falling back to name:en, then
     * the default name), or null when the point lies outside every stored country.
     * When no country polygon covers the point — a clipped regional extract never
     * imports one — falls back to the ISO code of the covering sub-national
     * boundary, localised through ICU.
     */
    public function findCountryAt(float $lat, float $lon, string $locale): ?string;

    /**
     * Resolves the ISO 3166-1 alpha-2 code of the country containing the given point,
     * or null when the point lies outside every stored country or the boundary carries
     * no ISO code. Callers needing a country-keyed dataset (public holidays…) use this
     * instead of the localised name.
     */
    public function findCountryCodeAt(float $lat, float $lon): ?string;

    /**
     * Resolves the locality (the municipal boundary, admin_level=8 in France)
     * containing the given point via ST_Covers, returning its name in $locale
     * (falling back to name:en, then the default name), or null when no municipal
     * boundary covers it — outside the provisioned zone, or in the fringe of a
     * clipped extract where the commune polygon could not be built.
     *
     * Offline replacement for the Nominatim reverse lookup used to label stage
     * endpoints (#880): the network round-trip left the request path entirely, and
     * the public instance's 1 req/s limit no longer caps the labelling.
     */
    public function findLocalityAt(float $lat, float $lon, string $locale): ?string;
}
