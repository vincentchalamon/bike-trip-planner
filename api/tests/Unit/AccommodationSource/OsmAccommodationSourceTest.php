<?php

declare(strict_types=1);

namespace App\Tests\Unit\AccommodationSource;

use App\AccommodationSource\OsmAccommodationSource;
use App\ApiResource\Model\Coordinate;
use App\Engine\PricingHeuristicEngine;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OsmAccommodationSourceTest extends TestCase
{
    #[Test]
    public function getNameReturnsOsm(): void
    {
        $source = $this->createSource();

        $this->assertSame('osm', $source->getName());
    }

    #[Test]
    public function isEnabledAlwaysReturnsTrue(): void
    {
        $source = $this->createSource();

        $this->assertTrue($source->isEnabled());
    }

    #[Test]
    public function fetchParsesNodeElementWithTourismTag(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'id' => 123,
                    'type' => 'node',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'tags' => ['tourism' => 'hotel', 'name' => 'Hotel du Nord'],
                ],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $source = $this->createSource($scanner, $queryBuilder);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('Hotel du Nord', $results[0]['name']);
        $this->assertSame('hotel', $results[0]['type']);
        $this->assertSame(48.6, $results[0]['lat']);
        $this->assertSame(2.6, $results[0]['lon']);
        $this->assertSame('osm', $results[0]['source']);
    }

    #[Test]
    public function fetchExtractsWikidataIdFromTags(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'id' => 456,
                    'type' => 'node',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'tags' => ['tourism' => 'hotel', 'name' => 'Hotel Wikidata', 'wikidata' => 'Q12345'],
                ],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $source = $this->createSource($scanner, $queryBuilder);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('Q12345', $results[0]['wikidataId']);
    }

    #[Test]
    public function fetchSetsNullWikidataIdWhenTagAbsent(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'id' => 789,
                    'type' => 'node',
                    'lat' => 48.6,
                    'lon' => 2.6,
                    'tags' => ['tourism' => 'hostel', 'name' => 'Hostel Central'],
                ],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $source = $this->createSource($scanner, $queryBuilder);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hostel']);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['wikidataId']);
    }

    #[Test]
    public function fetchUsesWayCenterCoordinatesWhenLatLonAbsent(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'id' => 101,
                    'type' => 'way',
                    'center' => ['lat' => 47.1, 'lon' => 3.2],
                    'tags' => ['tourism' => 'camp_site', 'name' => 'Camping du Lac'],
                ],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $source = $this->createSource($scanner, $queryBuilder);
        $results = $source->fetch([new Coordinate(47.0, 3.0)], 5000, ['camp_site']);

        $this->assertCount(1, $results);
        $this->assertSame(47.1, $results[0]['lat']);
        $this->assertSame(3.2, $results[0]['lon']);
    }

    #[Test]
    public function fetchSkipsElementsWithoutCoordinates(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'id' => 999,
                    'type' => 'way',
                    'tags' => ['tourism' => 'hotel', 'name' => 'No Coords Hotel'],
                ],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $source = $this->createSource($scanner, $queryBuilder);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertCount(0, $results);
    }

    #[Test]
    public function fetchMapsAmenityShelterToShelterType(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'id' => 200,
                    'type' => 'node',
                    'lat' => 46.0,
                    'lon' => 1.0,
                    'tags' => ['amenity' => 'shelter', 'shelter_type' => 'lean_to', 'name' => 'Lean-To'],
                ],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $source = $this->createSource($scanner, $queryBuilder);
        $results = $source->fetch([new Coordinate(46.0, 1.0)], 5000, ['shelter']);

        $this->assertCount(1, $results);
        $this->assertSame('shelter', $results[0]['type']);
        $this->assertSame(0.0, $results[0]['priceMin']);
        $this->assertSame(0.0, $results[0]['priceMax']);
    }

    #[Test]
    public function fetchReturnsEmptyArrayWhenNoElements(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildAccommodationQuery')->willReturn('query');

        $source = $this->createSource($scanner, $queryBuilder);
        $results = $source->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame([], $results);
    }

    private function createSource(
        ?ScannerInterface $scanner = null,
        ?QueryBuilderInterface $queryBuilder = null,
    ): OsmAccommodationSource {
        return new OsmAccommodationSource(
            scanner: $scanner ?? $this->createStub(ScannerInterface::class),
            queryBuilder: $queryBuilder ?? $this->createStub(QueryBuilderInterface::class),
            pricingEngine: new PricingHeuristicEngine(),
        );
    }
}
