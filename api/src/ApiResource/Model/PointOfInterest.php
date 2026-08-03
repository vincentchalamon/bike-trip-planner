<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;

final readonly class PointOfInterest
{
    public function __construct(
        public string $name,
        public string $category,
        public float $lat,
        public float $lon,
        public ?float $distanceFromStart = null,
        // The (osmType, osmId) pair is the primary key of the Tier-1 index and the
        // only stable identity an OSM entry has: it is what lets the rider open the
        // object on openstreetmap.org to check it still exists — or fix it at the
        // source. Null for a DataTourisme entry, which has no OSM identity.
        #[ApiProperty(description: 'OpenStreetMap object type: node, way or relation. Null when the entry does not come from OSM.')]
        public ?string $osmType = null,
        #[ApiProperty(description: 'OpenStreetMap object id. Null when the entry does not come from OSM.')]
        public ?int $osmId = null,
        #[ApiProperty(description: 'Raw OpenStreetMap opening_hours value, when the POI carries one.')]
        public ?string $openingHours = null,
        #[ApiProperty(description: 'Official website of the POI, when OpenStreetMap knows one.')]
        public ?string $website = null,
    ) {
    }
}
