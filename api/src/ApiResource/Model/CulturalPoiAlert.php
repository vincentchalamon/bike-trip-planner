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
 *
 * Optional enrichment fields (openingHours, estimatedPrice, description,
 * wikidataId, source) are populated when the POI comes from DataTourisme;
 * website comes from the OpenStreetMap `website` tag.
 */
final readonly class CulturalPoiAlert extends Alert
{
    public function __construct(
        AlertType $type,
        string $message,
        ?float $lat = null,
        ?float $lon = null,
        #[ApiProperty(description: 'POI name as found in OpenStreetMap or DataTourisme.')]
        public string $poiName = '',
        #[ApiProperty(description: 'POI type: museum, monument, castle, church, viewpoint, attraction, or historic.')]
        public string $poiType = '',
        #[ApiProperty(description: 'POI latitude.')]
        public float $poiLat = 0.0,
        #[ApiProperty(description: 'POI longitude.')]
        public float $poiLon = 0.0,
        #[ApiProperty(description: 'Approximate distance from the nearest route point, in metres.')]
        public int $distanceFromRoute = 0,
        ?AlertAction $action = null,
        #[ApiProperty(description: 'Opening hours as a human-readable string (DataTourisme only).')]
        public ?string $openingHours = null,
        #[ApiProperty(description: 'Official website of the POI, when OpenStreetMap knows one.')]
        public ?string $website = null,
        #[ApiProperty(description: 'Estimated entrance price in euros (DataTourisme only).')]
        public ?float $estimatedPrice = null,
        #[ApiProperty(description: 'Short description of the POI (DataTourisme only).')]
        public ?string $description = null,
        #[ApiProperty(description: 'Wikidata entity ID (e.g. Q12345) when available.')]
        public ?string $wikidataId = null,
        #[ApiProperty(description: 'Data source: osm or datatourisme.')]
        public ?string $source = null,
        #[ApiProperty(description: 'Thumbnail image URL from Wikimedia Commons.')]
        public ?string $imageUrl = null,
        #[ApiProperty(description: 'Wikipedia article URL.')]
        public ?string $wikipediaUrl = null,
        // The (osmType, osmId) pair is the primary key of the Tier-1 index and the
        // only stable identity an OSM entry has: it is what lets the rider open the
        // object on openstreetmap.org to check it still exists — or fix it at the
        // source. Null for a DataTourisme entry, which has no OSM identity.
        #[ApiProperty(description: 'OpenStreetMap object type: node, way or relation. Null when the entry does not come from OSM.')]
        public ?string $osmType = null,
        #[ApiProperty(description: 'OpenStreetMap object id. Null when the entry does not come from OSM.')]
        public ?int $osmId = null,
    ) {
        parent::__construct($type, $message, $lat, $lon, $action);
    }
}
