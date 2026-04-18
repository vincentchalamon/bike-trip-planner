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
    ) {
    }
}
