<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\DataTourismeMapper;

final class DataTourismeMapperTest extends TestCase
{
    private DataTourismeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new DataTourismeMapper();
    }

    /**
     * @param list<string>                    $type
     * @param array<string, mixed>            $extra
     * @param array{lat: string, lon: string} $geo
     *
     * @return array<string, mixed>
     */
    private function object(array $type, array $extra = [], array $geo = ['lat' => '49.1', 'lon' => '7.13']): array
    {
        return array_merge([
            '@id' => 'https://data.datatourisme.fr/10/abc',
            '@type' => $type,
            'rdfs:label' => ['fr' => ['Sample']],
            'isLocatedAt' => [[
                'schema:geo' => ['schema:latitude' => $geo['lat'], 'schema:longitude' => $geo['lon']],
            ]],
        ], $extra);
    }

    #[Test]
    public function mapsCulturalSiteToTheCulturalHead(): void
    {
        $row = $this->mapper->map($this->object(
            ['ArcheologicalSite', 'CulturalSite', 'PlaceOfInterest', 'PointOfInterest'],
            ['rdfs:comment' => ['fr' => ['Une villa gallo-romaine.']]],
        ));

        self::assertNotNull($row);
        self::assertSame('cultural', $row['head']);
        self::assertSame('monument', $row['category']);
        self::assertSame('Sample', $row['name']);
        self::assertEqualsWithDelta(49.1, $row['lat'], 0.0001);
        self::assertEqualsWithDelta(7.13, $row['lon'], 0.0001);
        self::assertSame('Une villa gallo-romaine.', $row['description']);
    }

    #[Test]
    public function mapsAccommodationWithCapacityAndPrice(): void
    {
        $row = $this->mapper->map($this->object(
            ['schema:Accommodation', 'Accommodation', 'RentalAccommodation', 'SelfCateringAccommodation'],
            [
                'allowedPersons' => 4,
                'offers' => [['schema:priceSpecification' => [['schema:price' => '75']]]],
            ],
        ));

        self::assertNotNull($row);
        self::assertSame('accommodation', $row['head']);
        self::assertSame('apartment', $row['category']);
        self::assertSame(4, $row['capacity']);
        self::assertSame(75.0, $row['price']);
    }

    #[Test]
    public function mapsAccommodationSubtypeToAppCategory(): void
    {
        $hotel = $this->mapper->map($this->object(['Accommodation', 'Hotel']));
        self::assertNotNull($hotel);
        self::assertSame('hotel', $hotel['category']);

        $camping = $this->mapper->map($this->object(['Accommodation', 'CampingAndCaravanning']));
        self::assertNotNull($camping);
        self::assertSame('camp_site', $camping['category']);
    }

    #[Test]
    public function mapsEventWithDates(): void
    {
        $row = $this->mapper->map($this->object(
            ['schema:Event', 'EntertainmentAndEvent', 'CulturalEvent', 'Festival'],
            ['schema:startDate' => ['2026-09-26'], 'schema:endDate' => ['2026-09-27']],
        ));

        self::assertNotNull($row);
        self::assertSame('event', $row['head']);
        self::assertSame('festival', $row['category']);
        self::assertSame('2026-09-26', $row['startDate']);
        self::assertSame('2026-09-27', $row['endDate']);
    }

    #[Test]
    public function classifiesEventBeforePlaceWhenBothTypesPresent(): void
    {
        // An event venue can also carry place types; the event head wins.
        $row = $this->mapper->map($this->object(['CulturalSite', 'EntertainmentAndEvent', 'SportsEvent']));

        self::assertNotNull($row);
        self::assertSame('event', $row['head']);
        self::assertSame('sports', $row['category']);
    }

    #[Test]
    public function ignoresUnsupportedCategories(): void
    {
        // Food establishments and shops are out of the import scope.
        self::assertNull($this->mapper->map($this->object(['FoodEstablishment', 'Restaurant'])));
        self::assertNull($this->mapper->map($this->object(['Store', 'BoutiqueOrLocalShop'])));
    }

    #[Test]
    public function returnsNullWithoutAnIdOrCoordinates(): void
    {
        $noId = $this->object(['CulturalSite']);
        unset($noId['@id']);
        self::assertNull($this->mapper->map($noId));

        $noGeo = $this->object(['CulturalSite']);
        unset($noGeo['isLocatedAt']);
        self::assertNull($this->mapper->map($noGeo));
    }

    #[Test]
    public function resolvesLabelByLanguagePreference(): void
    {
        $en = $this->object(['CulturalSite']);
        $en['rdfs:label'] = ['en' => ['English name'], 'de' => ['Deutscher Name']];
        $rowEn = $this->mapper->map($en);
        self::assertNotNull($rowEn);
        self::assertSame('English name', $rowEn['name'], 'falls back to en when fr is absent');

        $de = $this->object(['CulturalSite']);
        $de['rdfs:label'] = ['de' => ['Deutscher Name']];
        $rowDe = $this->mapper->map($de);
        self::assertNotNull($rowDe);
        self::assertSame('Deutscher Name', $rowDe['name'], 'falls back to any language as a last resort');
    }

    #[Test]
    public function extractsWikidataIdFromSameAs(): void
    {
        $object = $this->object(['CulturalSite'], [
            'owl:sameAs' => ['https://www.wikidata.org/entity/Q12345'],
        ]);

        $row = $this->mapper->map($object);
        self::assertNotNull($row);
        self::assertSame('Q12345', $row['wikidata']);
    }
}
