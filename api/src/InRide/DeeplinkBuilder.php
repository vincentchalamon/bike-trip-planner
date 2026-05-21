<?php

declare(strict_types=1);

namespace App\InRide;

use App\Geo\GeoPoint;

/**
 * Builds turn-by-turn navigation deeplinks from a rider's current position to a
 * point of interest.
 *
 * Google Maps directions URLs (https://developers.google.com/maps/documentation/urls/get-started#directions-action)
 * are the primary target — bicycling mode renders an actionable route on both
 * mobile and desktop. The OpenStreetMap-based URL is intended as a fallback for
 * users without Google Maps access (or for privacy-conscious riders).
 */
final readonly class DeeplinkBuilder
{
    /**
     * Returns a Google Maps directions URL in bicycling mode.
     *
     * Coordinates are serialised with enough precision (~1 cm) to match what
     * GPS devices export.
     */
    public function googleMapsBicycling(GeoPoint $origin, GeoPoint $destination): string
    {
        return \sprintf(
            'https://www.google.com/maps/dir/?api=1&origin=%s,%s&destination=%s,%s&travelmode=bicycling',
            $this->formatCoord($origin->lat),
            $this->formatCoord($origin->lon),
            $this->formatCoord($destination->lat),
            $this->formatCoord($destination->lon),
        );
    }

    /**
     * Returns an OpenStreetMap-based directions URL using the public OSRM bike
     * profile (engine=fossgis_osrm_bike). This is the fallback when Google Maps
     * is not available.
     */
    public function openStreetMap(GeoPoint $origin, GeoPoint $destination): string
    {
        return \sprintf(
            'https://www.openstreetmap.org/directions?engine=fossgis_osrm_bike&route=%s%%2C%s%%3B%s%%2C%s',
            $this->formatCoord($origin->lat),
            $this->formatCoord($origin->lon),
            $this->formatCoord($destination->lat),
            $this->formatCoord($destination->lon),
        );
    }

    private function formatCoord(float $value): string
    {
        // 7 decimal places ≈ 1.1 cm at the equator — plenty for navigation deeplinks.
        return rtrim(rtrim(\sprintf('%.7F', $value), '0'), '.');
    }
}
