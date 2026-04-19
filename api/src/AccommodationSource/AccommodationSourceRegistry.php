<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class AccommodationSourceRegistry
{
    /** @var list<AccommodationSourceInterface> */
    private readonly array $sources;

    /**
     * @param iterable<AccommodationSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.accommodation_source')]
        iterable $sources,
    ) {
        $this->sources = iterator_to_array($sources, false);
    }

    /**
     * Fetches candidates from all enabled sources and concatenates results.
     *
     * @param array<int, Coordinate> $endPoints
     * @param list<string>           $enabledTypes
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    public function fetchAll(array $endPoints, int $radiusMeters, array $enabledTypes): array
    {
        $all = [];

        foreach ($this->sources as $source) {
            if (!$source->isEnabled()) {
                continue;
            }

            foreach ($source->fetch($endPoints, $radiusMeters, $enabledTypes) as $candidate) {
                $all[] = $candidate;
            }
        }

        return $all;
    }
}
