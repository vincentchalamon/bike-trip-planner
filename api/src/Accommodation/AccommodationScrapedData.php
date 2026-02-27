<?php

declare(strict_types=1);

namespace App\Accommodation;

final readonly class AccommodationScrapedData
{
    public function __construct(
        public ?string $name,
        public ?string $type,
        public ?float $priceMin,
        public ?float $priceMax,
    ) {
    }
}
