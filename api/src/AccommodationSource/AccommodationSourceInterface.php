<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.accommodation_source')]
interface AccommodationSourceInterface
{
    /**
     * @param array<int, Coordinate> $endPoints
     * @param list<string>           $enabledTypes
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes): array;

    public function isEnabled(): bool;

    public function getName(): string;
}
