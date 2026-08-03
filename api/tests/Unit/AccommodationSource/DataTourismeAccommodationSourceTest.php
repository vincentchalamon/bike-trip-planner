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
    /**
     * One row as App\Tourism\AccommodationRepository returns it.
     *
     * @param array<string, string> $tags
     *
     * @return array{name: ?string, category: string, lat: float, lon: float, capacity: ?int, price: ?float, description: ?string, website: ?string, phone: ?string, openingHours: ?string, wikidata: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}
     */
    private function row(
        ?string $name = 'Gîte du Lac',
        string $category = 'rental',
        float $lat = 48.0,
        float $lon = 2.0,
        ?int $capacity = null,
        ?float $price = null,
        ?string $description = null,
        ?string $website = null,
        ?string $phone = null,
        ?string $openingHours = null,
        ?string $wikidata = null,
        ?string $imageUrl = null,
        ?string $wikipediaUrl = null,
        array $tags = [],
    ): array {
        return [
            'name' => $name,
            'category' => $category,
            'lat' => $lat,
            'lon' => $lon,
            'capacity' => $capacity,
            'price' => $price,
            'description' => $description,
            'website' => $website,
            'phone' => $phone,
            'openingHours' => $openingHours,
            'wikidata' => $wikidata,
            'imageUrl' => $imageUrl,
            'wikipediaUrl' => $wikipediaUrl,
            'tags' => $tags,
        ];
    }

    #[Test]
    public function usesTheExactOfferPriceWhenPresent(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            $this->row(capacity: 4, price: 75.0, description: 'Joli gîte'),
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
        // description comes from the DataTourisme flux; an entry the provisioner
        // could not tie to a Q-ID gets no Wikidata enrichment.
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
            $this->row(name: 'Hôtel du Parc', category: 'hotel'),
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
            $this->row(name: null, category: 'hotel'),
            $this->row(name: '   ', lat: 48.1, lon: 2.1),
            $this->row(lat: 48.2, lon: 2.2, capacity: 4, price: 75.0),
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
            $this->row(name: 'Fiche complète', category: 'hotel', capacity: 12, price: 75.0, description: 'Décrit'),
            $this->row(name: 'Fiche nue', category: 'hotel', lat: 48.1, lon: 2.1),
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
            $this->row(name: 'Hôtel du Parc', category: 'hotel'),
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
        $repository->method('findNear')->willReturn([$this->row(capacity: 4)]);

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
            $this->row(name: 'Gîte des Prés', capacity: 4),
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
     * The columns #872 added are what the rider verifies a booking with; the
     * `wikidata` one is also the only key NearbyNameDeduplicator can match an OSM
     * lodging on before falling back to name + 75 m.
     */
    #[Test]
    public function propagatesTheContactColumnsAndTheWikidataId(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            $this->row(
                name: 'Camping du Lac',
                category: 'camp_site',
                website: 'https://camping.test',
                phone: '+33 3 88 00 00 00',
                openingHours: 'Apr-Oct',
                wikidata: 'Q1234',
                imageUrl: 'https://img.test/camping.jpg',
                wikipediaUrl: 'https://fr.wikipedia.org/wiki/Camping',
            ),
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['camp_site']);

        self::assertSame('https://camping.test', $result[0]['url']);
        self::assertTrue($result[0]['hasWebsite']);
        self::assertSame('Q1234', $result[0]['wikidataId']);
        self::assertSame('Apr-Oct', $result[0]['openingHours']);
        self::assertSame('https://img.test/camping.jpg', $result[0]['imageUrl']);
        self::assertSame('https://fr.wikipedia.org/wiki/Camping', $result[0]['wikipediaUrl']);
    }

    /**
     * A value the flux published before #872 normalised it at import — or one that
     * only exists in the preserved tags — is still absolutised on read, and an
     * unusable one is dropped rather than handed to the rider as a dead link.
     */
    #[Test]
    public function normalisesTheWebsiteItReads(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            $this->row(name: 'Gîte sans schéma', website: 'www.gite.test/chambres'),
            $this->row(name: 'Gîte injoignable', lat: 48.1, lon: 2.1, website: 'nous contacter'),
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['rental']);

        self::assertSame('https://www.gite.test/chambres', $result[0]['url']);
        self::assertTrue($result[0]['hasWebsite']);
        self::assertNull($result[1]['url']);
        self::assertFalse($result[1]['hasWebsite']);
    }

    /**
     * The source used to pass `'tags' => []` whatever the index held, so the flux
     * contact and opening hours preserved by the provisioner never reached a
     * consumer (#871). They remain the fallback for rows imported before #872 gave
     * them columns, and the only home of `booking_url` / `image_url`.
     */
    #[Test]
    public function propagatesThePreservedFluxTags(): void
    {
        $repository = $this->createStub(AccommodationRepositoryInterface::class);
        $repository->method('findNear')->willReturn([
            $this->row(
                name: 'Camping du Lac',
                category: 'camp_site',
                tags: ['website' => 'https://camping.test', 'phone' => '+33 3 88 00 00 00', 'opening_hours' => 'Apr-Oct'],
            ),
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
            $this->row(tags: ['booking_url' => 'https://booking.test/gite', 'image_url' => 'https://cdn.test/gite.jpg']),
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['rental']);

        self::assertSame('https://booking.test/gite', $result[0]['url']);
        self::assertTrue($result[0]['hasWebsite']);
        // The flux photo has no column of its own: image_url is Wikidata-only.
        self::assertSame('https://cdn.test/gite.jpg', $result[0]['imageUrl']);
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
            $this->row(name: 'Camping du Lac', category: 'camp_site', tags: ['opening_hours' => 'Apr-Oct']),
        ]);

        $result = new DataTourismeAccommodationSource($repository, new PricingHeuristicEngine())
            ->fetch([new Coordinate(48.0, 2.0)], 5000, ['camp_site']);

        $checker = new SeasonalityChecker();
        self::assertFalse($checker->isLikelyOpen(new \DateTimeImmutable('2026-01-15'), $result[0]['tags']), 'closed in January → possibleClosed');
        self::assertTrue($checker->isLikelyOpen(new \DateTimeImmutable('2026-06-15'), $result[0]['tags']));
    }
}
