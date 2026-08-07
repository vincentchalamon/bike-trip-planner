<?php

declare(strict_types=1);

namespace App\InRide;

use App\Geo\GeoPoint;

/**
 * Builds a turn-by-turn navigation deeplink to a point of interest.
 *
 * Google Maps directions URLs (https://developers.google.com/maps/documentation/urls/get-started#directions-action)
 * are the target — bicycling mode renders an actionable route on both mobile and
 * desktop. Only the destination is encoded: the rider's live position is left to
 * the map app so it never leaves this backend in a URL.
 */
final readonly class DeeplinkBuilder
{
    /**
     * Returns a Google Maps directions URL in bicycling mode to the destination.
     *
     * Coordinates are serialised with enough precision (~1 cm) to match what
     * GPS devices export.
     */
    public function googleMapsBicycling(GeoPoint $destination): string
    {
        return \sprintf(
            'https://www.google.com/maps/dir/?api=1&destination=%s,%s&travelmode=bicycling',
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
