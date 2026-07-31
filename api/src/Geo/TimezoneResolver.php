<?php

declare(strict_types=1);

namespace App\Geo;

use App\Osm\AdminBoundaryRepositoryInterface;

/**
 * Resolves a timezone from a position, with no external service and no extra
 * dependency: the country is read from the local admin-boundary index (ADR-040),
 * which narrows the candidates to that country's zones, then the closest reference
 * location among them wins (\DateTimeZone::getLocation()).
 *
 * The country step matters: a plain nearest-city match over all identifiers puts
 * Nantes on Europe/Jersey (UTC+0), an hour off. Narrowing to FR leaves Europe/Paris.
 * When no boundary resolves — admin_level=2 relations can be missing from a regional
 * OSM extract — the search falls back to the closest reference location worldwide,
 * which is still far better than assuming UTC.
 */
final class TimezoneResolver implements TimezoneResolverInterface
{
    /** @var list<array{id: string, lat: float, lon: float}>|null */
    private ?array $worldLocations = null;

    public function __construct(
        private readonly GeoDistanceInterface $distance,
        private readonly AdminBoundaryRepositoryInterface $adminBoundaryRepository,
    ) {
    }

    public function resolve(float $lat, float $lon): \DateTimeZone
    {
        $countryCode = $this->adminBoundaryRepository->findCountryCodeAt($lat, $lon);
        $candidates = null !== $countryCode
            ? \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $countryCode)
            : [];

        if (1 === \count($candidates)) {
            return new \DateTimeZone($candidates[0]);
        }

        $locations = [] !== $candidates ? $this->locationsOf($candidates) : $this->worldLocations();
        $nearestId = $this->nearest($lat, $lon, $locations) ?? ($candidates[0] ?? null);

        return new \DateTimeZone($nearestId ?? 'UTC');
    }

    /**
     * @param list<array{id: string, lat: float, lon: float}> $locations
     */
    private function nearest(float $lat, float $lon, array $locations): ?string
    {
        $nearestId = null;
        $nearestDistance = PHP_FLOAT_MAX;

        foreach ($locations as $location) {
            $candidate = $this->distance->inMeters($lat, $lon, $location['lat'], $location['lon']);
            if ($candidate < $nearestDistance) {
                $nearestDistance = $candidate;
                $nearestId = $location['id'];
            }
        }

        return $nearestId;
    }

    /**
     * @return list<array{id: string, lat: float, lon: float}>
     */
    private function worldLocations(): array
    {
        return $this->worldLocations ??= $this->locationsOf(\DateTimeZone::listIdentifiers());
    }

    /**
     * @param list<string> $identifiers
     *
     * @return list<array{id: string, lat: float, lon: float}>
     */
    private function locationsOf(array $identifiers): array
    {
        $locations = [];

        foreach ($identifiers as $identifier) {
            $location = new \DateTimeZone($identifier)->getLocation();
            if (false === $location) {
                continue;
            }

            $locations[] = [
                'id' => $identifier,
                'lat' => $location['latitude'],
                'lon' => $location['longitude'],
            ];
        }

        return $locations;
    }
}
