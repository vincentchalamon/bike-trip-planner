<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;

final readonly class Accommodation
{
    public function __construct(
        public string $name,
        public string $type,
        public float $lat,
        public float $lon,
        public float $estimatedPriceMin,
        public float $estimatedPriceMax,
        public bool $isExactPrice,
        public ?string $url = null,
        public bool $possibleClosed = false,
        public float $distanceToEndPoint = 0.0,
        public string $source = 'osm',
        #[ApiProperty(description: 'Short description from Wikidata.')]
        public ?string $description = null,
        #[ApiProperty(description: 'Thumbnail image URL from Wikimedia Commons.')]
        public ?string $imageUrl = null,
        #[ApiProperty(description: 'Wikipedia article URL.')]
        public ?string $wikipediaUrl = null,
        #[ApiProperty(description: 'Opening hours (Wikidata P8989 or DataTourisme).')]
        public ?string $openingHours = null,
        #[ApiProperty(description: 'Contact phone number, from the OSM contact block or the DataTourisme flux.')]
        public ?string $phone = null,
        // The (osmType, osmId) pair is the primary key of the Tier-1 index and the
        // only stable identity an OSM entry has: it is what lets the rider open the
        // object on openstreetmap.org to check it still exists — or fix it at the
        // source. Null for a DataTourisme entry, which has no OSM identity.
        #[ApiProperty(description: 'OpenStreetMap object type: node, way or relation. Null when the entry does not come from OSM.')]
        public ?string $osmType = null,
        #[ApiProperty(description: 'OpenStreetMap object id. Null when the entry does not come from OSM.')]
        public ?int $osmId = null,
    ) {
    }
}
