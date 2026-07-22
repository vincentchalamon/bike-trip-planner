<?php

declare(strict_types=1);

namespace App\Tests\Unit\AccommodationSource;

use App\AccommodationSource\DataTourismeAccommodationSource;
use App\ApiResource\Model\Coordinate;
use App\Engine\PricingHeuristicEngine;
use App\Tourism\AccommodationRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataTourismeAccommodationSourceTest extends TestCase
{
    #[Test]
    public function usesTheExactOfferPriceWhenPresent(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => 'Gîte du Lac', 'category' => 'apartment', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => 4, 'price' => 75.0, 'description' => 'Joli gîte'],
        ]);

        // The heuristic engine is final and cannot be doubled, so we pass a real
        // one; with an exact flux price it is not consulted.
        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['apartment']);

        self::assertCount(1, $result);
        self::assertSame('Gîte du Lac', $result[0]['name']);
        self::assertSame(75.0, $result[0]['priceMin']);
        self::assertSame(75.0, $result[0]['priceMax']);
        self::assertTrue($result[0]['isExact']);
        self::assertSame('datatourisme', $result[0]['source']);
        self::assertNull($result[0]['wikidataId']);
        // description comes from the DataTourisme flux; tourism.accommodations is
        // not Wikidata-enriched, so the Wikidata-only fields stay null by design.
        self::assertSame('Joli gîte', $result[0]['description']);
        self::assertNull($result[0]['imageUrl']);
        self::assertNull($result[0]['wikipediaUrl']);
        self::assertNull($result[0]['openingHours']);
    }

    #[Test]
    public function fallsBackToTheCategoryHeuristicWithoutAPrice(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => 'Hôtel du Parc', 'category' => 'hotel', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => null, 'price' => null, 'description' => null],
        ]);

        $engine = new PricingHeuristicEngine();
        $expected = $engine->estimatePrice('hotel', []);

        $result = new DataTourismeAccommodationSource($repository, $engine)
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['hotel']);

        self::assertSame('Hôtel du Parc', $result[0]['name']);
        self::assertSame($expected['min'], $result[0]['priceMin']);
        self::assertSame($expected['max'], $result[0]['priceMax']);
        self::assertFalse($result[0]['isExact']);
    }

    #[Test]
    public function skipsUnnamedEntries(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => null, 'category' => 'hotel', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => null, 'price' => null, 'description' => null],
            ['name' => '   ', 'category' => 'apartment', 'lat' => 48.1, 'lon' => 2.1, 'capacity' => null, 'price' => null, 'description' => null],
            ['name' => 'Gîte du Lac', 'category' => 'apartment', 'lat' => 48.2, 'lon' => 2.2, 'capacity' => 4, 'price' => 75.0, 'description' => null],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['hotel', 'apartment']);

        self::assertCount(1, $result);
        self::assertSame('Gîte du Lac', $result[0]['name']);
    }
}
