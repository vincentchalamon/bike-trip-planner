<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;

final readonly class Event
{
    public function __construct(
        public string $name,
        public string $type,
        public float $lat,
        public float $lon,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?string $url = null,
        public ?string $description = null,
        public ?float $priceMin = null,
        public float $distanceToEndPoint = 0.0,
        public string $source = 'datatourisme',
        public ?string $wikidataId = null,
        #[ApiProperty(description: 'Thumbnail image URL from Wikimedia Commons.')]
        public ?string $imageUrl = null,
        #[ApiProperty(description: 'Wikipedia article URL.')]
        public ?string $wikipediaUrl = null,
        #[ApiProperty(description: 'Opening hours (Wikidata P8989).')]
        public ?string $openingHours = null,
    ) {
    }
}
