<?php

declare(strict_types=1);

namespace App\Tests\Unit\AccommodationSource;

use App\AccommodationSource\OsmAccommodationSource;
use App\ApiResource\Model\Coordinate;
use App\Engine\PricingHeuristicEngine;
use App\Osm\AccommodationRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OsmAccommodationSourceTest extends TestCase
{
    #[Test]
    public function getNameReturnsOsm(): void
    {
        $this->assertSame('osm', $this->createSource()->getName());
    }

    #[Test]
    public function isEnabledAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->createSource()->isEnabled());
    }

    #[Test]
    public function fetchMapsRepositoryRowToCandidate(): void
    {
        $source = $this->createSource($this->repository([$this->row(category: 'hotel', name: 'Hotel du Nord')]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('Hotel du Nord', $results[0]['name']);
        $this->assertSame('hotel', $results[0]['type']);
        $this->assertSame(48.6, $results[0]['lat']);
        $this->assertSame(2.6, $results[0]['lon']);
        $this->assertSame('osm', $results[0]['source']);
        $this->assertSame(0, $results[0]['tagCount']);
    }

    #[Test]
    public function fetchSkipsUnnamedEntries(): void
    {
        $source = $this->createSource($this->repository([
            $this->row(category: 'shelter', name: null),
            $this->row(category: 'shelter', name: '  '),
            $this->row(category: 'hotel', name: 'Hotel du Nord'),
        ]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['shelter', 'hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('Hotel du Nord', $results[0]['name']);
    }

    #[Test]
    public function fetchUsesWikidataFromRepositoryRow(): void
    {
        $source = $this->createSource($this->repository([$this->row(wikidata: 'Q12345')]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame('Q12345', $results[0]['wikidataId']);
    }

    #[Test]
    public function fetchSetsNullWikidataIdWhenAbsent(): void
    {
        $source = $this->createSource($this->repository([$this->row()]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertNull($results[0]['wikidataId']);
    }

    #[Test]
    public function fetchPricesShelterAtZero(): void
    {
        $source = $this->createSource($this->repository([$this->row(category: 'shelter')]));

        $results = $source->fetch([new Coordinate(46.0, 1.0)], 5000, ['shelter']);

        $this->assertSame('shelter', $results[0]['type']);
        $this->assertSame(0.0, $results[0]['priceMin']);
        $this->assertSame(0.0, $results[0]['priceMax']);
        $this->assertFalse($results[0]['isExact']);
    }

    #[Test]
    public function fetchUsesExactPriceFromChargeTag(): void
    {
        $source = $this->createSource($this->repository([$this->row(category: 'camp_site', tags: ['charge' => '15 EUR'])]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['camp_site']);

        $this->assertSame(15.0, $results[0]['priceMin']);
        $this->assertSame(15.0, $results[0]['priceMax']);
        $this->assertTrue($results[0]['isExact']);
        $this->assertSame(1, $results[0]['tagCount']);
    }

    #[Test]
    public function fetchDerivesUrlAndHasWebsiteFromWebsiteColumn(): void
    {
        $source = $this->createSource($this->repository([$this->row(website: 'https://hotel.example')]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame('https://hotel.example', $results[0]['url']);
        $this->assertTrue($results[0]['hasWebsite']);
    }

    #[Test]
    public function fetchFallsBackToContactWebsiteTagForUrl(): void
    {
        $source = $this->createSource($this->repository([$this->row(tags: ['contact:website' => 'https://contact.example'])]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame('https://contact.example', $results[0]['url']);
        $this->assertTrue($results[0]['hasWebsite']);
    }

    #[Test]
    public function fetchSetsNoUrlWhenNeitherWebsiteNorContactPresent(): void
    {
        $source = $this->createSource($this->repository([$this->row()]));

        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertNull($results[0]['url']);
        $this->assertFalse($results[0]['hasWebsite']);
    }

    #[Test]
    public function propagatesEnrichmentColumnsFromRepository(): void
    {
        $row = $this->row(category: 'hotel', name: 'Hotel Enrichi', wikidata: 'Q99');
        $row['description'] = 'Bel hôtel';
        $row['imageUrl'] = 'https://img.test/hotel.jpg';
        $row['wikipediaUrl'] = 'https://fr.wikipedia.org/wiki/Hotel';
        $row['openingHours'] = 'Mo-Fr 08:00-22:00';

        $results = $this->createSource($this->repository([$row]))->fetch([new Coordinate(48.0, 2.0)], 5000, ['hotel']);

        self::assertSame('Bel hôtel', $results[0]['description']);
        self::assertSame('https://img.test/hotel.jpg', $results[0]['imageUrl']);
        self::assertSame('https://fr.wikipedia.org/wiki/Hotel', $results[0]['wikipediaUrl']);
        self::assertSame('Mo-Fr 08:00-22:00', $results[0]['openingHours']);
    }

    #[Test]
    public function fetchPassesPointsRadiusAndEnabledTypesToRepository(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturnCallback(
            static function (array $points, int $radiusMeters, array $categories): array {
                self::assertSame([['lat' => 48.5, 'lon' => 2.5]], $points);
                self::assertSame(5000, $radiusMeters);
                self::assertSame(['hotel', 'hostel'], $categories);

                return [];
            },
        );

        $this->createSource($repository)->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel', 'hostel']);
    }

    #[Test]
    public function fetchReturnsEmptyArrayWhenRepositoryReturnsNothing(): void
    {
        $results = $this->createSource($this->repository([]))->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame([], $results);
    }

    /**
     * @param array<string, string> $tags
     *
     * @return array{name: ?string, category: string, lat: float, lon: float, stars: ?int, capacity: ?int, fee: ?string, website: ?string, wikidata: ?string, openingHours: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}
     */
    private function row(
        string $category = 'hotel',
        ?string $name = 'Hotel du Nord',
        ?string $website = null,
        ?string $wikidata = null,
        array $tags = [],
    ): array {
        return [
            'name' => $name,
            'category' => $category,
            'lat' => 48.6,
            'lon' => 2.6,
            'stars' => null,
            'capacity' => null,
            'fee' => null,
            'website' => $website,
            'wikidata' => $wikidata,
            'openingHours' => null,
            'description' => null,
            'imageUrl' => null,
            'wikipediaUrl' => null,
            'tags' => $tags,
        ];
    }

    /**
     * @param list<array{name: ?string, category: string, lat: float, lon: float, stars: ?int, capacity: ?int, fee: ?string, website: ?string, wikidata: ?string, openingHours: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}> $rows
     */
    private function repository(array $rows): AccommodationRepositoryInterface
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn($rows);

        return $repository;
    }

    private function createSource(?AccommodationRepositoryInterface $repository = null): OsmAccommodationSource
    {
        return new OsmAccommodationSource(
            accommodationRepository: $repository ?? $this->createStub(AccommodationRepositoryInterface::class),
            pricingEngine: new PricingHeuristicEngine(),
        );
    }
}
