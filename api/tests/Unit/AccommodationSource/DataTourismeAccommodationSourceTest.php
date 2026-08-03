<?php

declare(strict_types=1);

namespace App\Tests\Unit\AccommodationSource;

use App\Accommodation\SeasonalityChecker;
use App\AccommodationSource\DataTourismeAccommodationSource;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
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
            ['name' => 'Gîte du Lac', 'category' => 'rental', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => 4, 'price' => 75.0, 'description' => 'Joli gîte', 'tags' => []],
        ]);

        // The heuristic engine is final and cannot be doubled, so we pass a real
        // one; with an exact flux price it is not consulted.
        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['rental']);

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
            ['name' => 'Hôtel du Parc', 'category' => 'hotel', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => null, 'price' => null, 'description' => null, 'tags' => []],
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
            ['name' => null, 'category' => 'hotel', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => null, 'price' => null, 'description' => null, 'tags' => []],
            ['name' => '   ', 'category' => 'rental', 'lat' => 48.1, 'lon' => 2.1, 'capacity' => null, 'price' => null, 'description' => null, 'tags' => []],
            ['name' => 'Gîte du Lac', 'category' => 'rental', 'lat' => 48.2, 'lon' => 2.2, 'capacity' => 4, 'price' => 75.0, 'description' => null, 'tags' => []],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['hotel', 'rental']);

        self::assertCount(1, $result);
        self::assertSame('Gîte du Lac', $result[0]['name']);
    }

    #[Test]
    public function countsTheAttributesTheFluxActuallyFilled(): void
    {
        // `tagCount` used to be hardcoded to 0, which penalised the curated source on
        // the only quality signals available (#869). It now counts the filled fields:
        // name + category for the bare entry, plus description/capacity/price.
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => 'Fiche complète', 'category' => 'hotel', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => 12, 'price' => 75.0, 'description' => 'Décrit', 'tags' => []],
            ['name' => 'Fiche nue', 'category' => 'hotel', 'lat' => 48.1, 'lon' => 2.1, 'capacity' => null, 'price' => null, 'description' => null, 'tags' => []],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['hotel']);

        self::assertSame(5, $result[0]['tagCount']);
        self::assertSame(2, $result[1]['tagCount']);
    }

    #[Test]
    public function derivesHasWebsiteFromTheExposedUrl(): void
    {
        // An entry the flux published without any contact exposes no URL, and
        // `hasWebsite` follows it instead of being asserted.
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => 'Hôtel du Parc', 'category' => 'hotel', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => null, 'price' => null, 'description' => null, 'tags' => []],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['hotel']);

        self::assertNull($result[0]['url']);
        self::assertFalse($result[0]['hasWebsite']);
    }

    #[Test]
    public function propagatesCapacityAndLeavesStarsAndFeeUnknown(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => 'Gîte du Lac', 'category' => 'rental', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => 4, 'price' => null, 'description' => null, 'tags' => []],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['rental']);

        self::assertSame(4, $result[0]['capacity']);
        self::assertNull($result[0]['stars']);
        self::assertNull($result[0]['fee']);
    }

    #[Test]
    public function exposesTheRentalCategoryAsAFilterableType(): void
    {
        // `rental` (meublé de tourisme) must belong to the searchable vocabulary,
        // otherwise the repositories' `category IN (:categories)` filter can never
        // return the ~80k DataTourisme rentals (issue #865).
        self::assertContains('rental', TripRequest::ALL_ACCOMMODATION_TYPES);

        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            ['name' => 'Gîte des Prés', 'category' => 'rental', 'lat' => 48.0, 'lon' => 2.0, 'capacity' => 4, 'price' => null, 'description' => null, 'tags' => []],
        ]);

        $engine = new PricingHeuristicEngine();
        $expected = $engine->estimatePrice('rental', []);

        $result = new DataTourismeAccommodationSource($repository, $engine)
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['rental']);

        self::assertCount(1, $result);
        self::assertSame('rental', $result[0]['type']);
        self::assertSame($expected['min'], $result[0]['priceMin']);
        self::assertSame($expected['max'], $result[0]['priceMax']);
    }

    /**
     * The source used to pass `'tags' => []` whatever the index held, so the flux
     * contact and opening hours preserved by the provisioner never reached a
     * consumer (#871).
     */
    #[Test]
    public function propagatesThePreservedFluxTags(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            [
                'name' => 'Camping du Lac', 'category' => 'camp_site', 'lat' => 48.0, 'lon' => 2.0,
                'capacity' => null, 'price' => null, 'description' => null,
                'tags' => ['website' => 'https://camping.test', 'phone' => '+33 3 88 00 00 00', 'opening_hours' => 'Apr-Oct'],
            ],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['camp_site']);

        self::assertSame(
            ['website' => 'https://camping.test', 'phone' => '+33 3 88 00 00 00', 'opening_hours' => 'Apr-Oct'],
            $result[0]['tags'],
        );
        self::assertSame('https://camping.test', $result[0]['url']);
        self::assertTrue($result[0]['hasWebsite']);
        self::assertSame('Apr-Oct', $result[0]['openingHours']);
    }

    #[Test]
    public function fallsBackToTheBookingUrlWhenNoWebsiteWasPublished(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            [
                'name' => 'Gîte du Lac', 'category' => 'rental', 'lat' => 48.0, 'lon' => 2.0,
                'capacity' => null, 'price' => null, 'description' => null,
                'tags' => ['booking_url' => 'https://booking.test/gite'],
            ],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['rental']);

        self::assertSame('https://booking.test/gite', $result[0]['url']);
        self::assertTrue($result[0]['hasWebsite']);
    }

    /**
     * End of the chain the empty tags array used to cut: ScanAccommodationsHandler
     * feeds `$raw['tags']` to the SeasonalityChecker, which could never return
     * anything but null on a DataTourisme entry.
     */
    #[Test]
    public function letsTheSeasonalityCheckerDecideOnADataTourismeEntry(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            [
                'name' => 'Camping du Lac', 'category' => 'camp_site', 'lat' => 48.0, 'lon' => 2.0,
                'capacity' => null, 'price' => null, 'description' => null,
                'tags' => ['opening_hours' => 'Apr-Oct'],
            ],
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['camp_site']);

        $checker = new SeasonalityChecker();
        self::assertFalse($checker->isLikelyOpen(new \DateTimeImmutable('2026-01-15'), $result[0]['tags']), 'closed in January → possibleClosed');
        self::assertTrue($checker->isLikelyOpen(new \DateTimeImmutable('2026-06-15'), $result[0]['tags']));
    }
}
