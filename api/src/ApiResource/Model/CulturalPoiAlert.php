<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use App\Enum\AlertType;

/**
 * Extended Alert DTO for cultural POI suggestions.
 *
 * Carries the POI coordinates and metadata needed to allow the frontend
 * to offer an "add to itinerary" action (triggering route recalculation
 * via the RecalculateRouteSegment Messenger message — ADR-017).
 */
final readonly class CulturalPoiAlert extends Alert
{
    public function __construct(
        AlertType $type,
        string $message,
        ?float $lat = null,
        ?float $lon = null,

        #[ApiProperty(description: 'POI name as found in OpenStreetMap.')]
        public string $poiName = '',

        #[ApiProperty(description: 'POI type: museum, monument, castle, church, viewpoint, attraction, or historic.')]
        public string $poiType = '',

        #[ApiProperty(description: 'POI latitude.')]
        public float $poiLat = 0.0,

        #[ApiProperty(description: 'POI longitude.')]
        public float $poiLon = 0.0,

        #[ApiProperty(description: 'Approximate distance from the nearest route point, in metres.')]
        public int $distanceFromRoute = 0,
    ) {
        parent::__construct($type, $message, $lat, $lon);
    }
}
