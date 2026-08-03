<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame('rental', $row['category']);
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

    /**
     * The whole French "meublé de tourisme" market collapses onto the single
     * `rental` category, so it stays comparable with OSM's tourism=apartment.
     */
    #[Test]
    #[DataProvider('rentalSubtypes')]
    public function mapsEveryRentalSubtypeToTheSingleRentalCategory(string $subtype): void
    {
        $row = $this->mapper->map($this->object(['Accommodation', $subtype]));

        self::assertNotNull($row);
        self::assertSame('accommodation', $row['head']);
        self::assertSame('rental', $row['category']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rentalSubtypes(): iterable
    {
        yield 'RentalAccommodation' => ['RentalAccommodation'];
        yield 'SelfCateringAccommodation' => ['SelfCateringAccommodation'];
        yield 'House' => ['House'];
        yield 'Apartment' => ['Apartment'];
        yield 'Bungalow' => ['Bungalow'];
        yield 'Yurt' => ['Yurt'];
        yield 'CastleAndPrestigeMansion' => ['CastleAndPrestigeMansion'];
    }

    #[Test]
    public function discardsAccommodationProductWhichIsAnOfferNotAPlace(): void
    {
        // AccommodationProduct is a commercial offer; on its own it describes no
        // place, so it must not be mapped nor imported.
        self::assertNull($this->mapper->map($this->object(['schema:Accommodation', 'Accommodation', 'AccommodationProduct'])));

        // In the flux it always co-occurs with a place subtype, which is what
        // classifies the object: those rows are still imported as rentals.
        $row = $this->mapper->map($this->object(['Accommodation', 'AccommodationProduct', 'RentalAccommodation']));
        self::assertNotNull($row);
        self::assertSame('rental', $row['category']);
    }

    #[Test]
    public function discardsAndCountsAccommodationsWithAnUnmappedSubtype(): void
    {
        self::assertSame(0, $this->mapper->unmappedAccommodationCount());

        // No silent default: an unknown subtype must not land in a bucket outside
        // TripRequest::ALL_ACCOMMODATION_TYPES, which would be unreachable.
        self::assertNull($this->mapper->map($this->object(['schema:Accommodation', 'Accommodation', 'PlaceOfInterest'])));
        self::assertNull($this->mapper->map($this->object(['Accommodation', 'SomeBrandNewOntologyType'])));

        self::assertSame(2, $this->mapper->unmappedAccommodationCount());

        // Mapped accommodations do not inflate the counter.
        self::assertNotNull($this->mapper->map($this->object(['Accommodation', 'Hotel'])));
        self::assertSame(2, $this->mapper->unmappedAccommodationCount());
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
    public function mapsFoodEstablishmentToTheFoodHead(): void
    {
        $restaurant = $this->mapper->map($this->object(['schema:FoodEstablishment', 'schema:Restaurant', 'FoodEstablishment', 'Restaurant']));
        self::assertNotNull($restaurant);
        self::assertSame('food', $restaurant['head']);
        self::assertSame('restaurant', $restaurant['category']);

        $bar = $this->mapper->map($this->object(['FoodEstablishment', 'BarOrPub']));
        self::assertNotNull($bar);
        self::assertSame('food', $bar['head']);
        self::assertSame('bar', $bar['category']);

        $fastFood = $this->mapper->map($this->object(['FastFoodRestaurant', 'FoodEstablishment', 'Restaurant']));
        self::assertNotNull($fastFood);
        self::assertSame('fast_food', $fastFood['category']);

        // A bare FoodEstablishment with no known subtype defaults to restaurant.
        $bare = $this->mapper->map($this->object(['FoodEstablishment', 'PlaceOfInterest']));
        self::assertNotNull($bare);
        self::assertSame('restaurant', $bare['category']);

        // CulturalSite wins over FoodEstablishment (cultural check runs first in classify()).
        $cultural = $this->mapper->map($this->object(['CulturalSite', 'FoodEstablishment', 'Restaurant']));
        self::assertNotNull($cultural);
        self::assertSame('cultural', $cultural['head']);
    }

    #[Test]
    public function mapsFoodShopsButSkipsNonFoodStores(): void
    {
        // A bakery shop (Store + Bakery) and a local-products shop are resupply-relevant.
        $bakery = $this->mapper->map($this->object(['Bakery', 'BoutiqueOrLocalShop', 'FoodEstablishment', 'Store']));
        self::assertNotNull($bakery);
        self::assertSame('food', $bakery['head']);
        self::assertSame('bakery', $bakery['category']);

        $farm = $this->mapper->map($this->object(['LocalProductsShop', 'Store']));
        self::assertNotNull($farm);
        self::assertSame('food', $farm['head']);
        self::assertSame('farm', $farm['category']);

        // Non-food stores (boutiques, craftsmen, parking, taxis) carry no resupply
        // value and must be skipped.
        self::assertNull($this->mapper->map($this->object(['Store', 'BoutiqueOrLocalShop'])));
        self::assertNull($this->mapper->map($this->object(['Store', 'CraftsmanShop'])));
        self::assertNull($this->mapper->map($this->object(['Store', 'Parking', 'Transport'])));
    }

    #[Test]
    public function classifiesHotelRestaurantAsAccommodationNotFood(): void
    {
        // A HotelRestaurant carries both Accommodation and FoodEstablishment; lodging wins.
        $row = $this->mapper->map($this->object(['Accommodation', 'FoodEstablishment', 'Hotel', 'HotelRestaurant', 'Restaurant']));

        self::assertNotNull($row);
        self::assertSame('accommodation', $row['head']);
        self::assertSame('hotel', $row['category']);
    }

    #[Test]
    public function ignoresUnsupportedCategories(): void
    {
        // A type list with no place/event/food head we map is skipped.
        self::assertNull($this->mapper->map($this->object(['PlaceOfInterest', 'PointOfInterest'])));
        self::assertNull($this->mapper->map($this->object(['ServiceProvider', 'ActivityProvider'])));
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

    /**
     * A full flux object: everything the source publishes and the row has no column
     * for must survive in `tags`, or it is unrecoverable short of a re-import (#871).
     */
    #[Test]
    public function preservesTheContactAddressMediaAndLabelsInTags(): void
    {
        $row = $this->mapper->map($this->object(['CulturalSite', 'Museum'], [
            'hasContact' => [[
                '@type' => ['Agent'],
                'schema:telephone' => ['+33 3 88 00 00 00'],
                'schema:email' => ['contact@musee.test'],
                'foaf:homepage' => ['https://musee.test'],
            ]],
            'hasBookingContact' => [[
                '@type' => ['Agent'],
                'foaf:homepage' => ['https://booking.test/musee'],
            ]],
            'isLocatedAt' => [[
                '@type' => ['PlaceOfInterest'],
                'schema:geo' => ['schema:latitude' => '49.1', 'schema:longitude' => '7.13'],
                'schema:address' => [[
                    '@type' => ['schema:PostalAddress'],
                    'schema:streetAddress' => ['1 place du Château'],
                    'schema:postalCode' => '67000',
                    'hasAddressCity' => ['@type' => ['City'], 'rdfs:label' => ['fr' => ['Strasbourg']]],
                ]],
            ]],
            'hasMainRepresentation' => [[
                '@type' => ['MediaRepresentation'],
                'ebucore:hasRelatedResource' => [[
                    '@type' => ['MediaResource'],
                    'ebucore:locator' => ['https://cdn.test/musee.jpg'],
                ]],
            ]],
            'hasClassification' => [['@type' => ['TouristicLabel'], 'rdfs:label' => ['fr' => ['Accueil Vélo']]]],
            'hasFeature' => [['@type' => ['Equipment'], 'rdfs:label' => ['fr' => ['Parking à vélos']]]],
            // Bulk free text and provenance are deliberately NOT kept.
            'hasReview' => [['@type' => ['Review'], 'rdfs:comment' => ['fr' => ['Un très long avis.']]]],
            'lastUpdateDatatourisme' => '2026-07-31',
        ]));

        self::assertNotNull($row);
        self::assertSame('https://musee.test', $row['website']);
        self::assertSame([
            'type' => ['CulturalSite', 'Museum'],
            'website' => 'https://musee.test',
            'phone' => '+33 3 88 00 00 00',
            'email' => 'contact@musee.test',
            'booking_url' => 'https://booking.test/musee',
            'address' => '1 place du Château',
            'postal_code' => '67000',
            'city' => 'Strasbourg',
            'image_url' => 'https://cdn.test/musee.jpg',
            'labels' => ['Accueil Vélo', 'Parking à vélos'],
        ], $row['tags']);
    }

    #[Test]
    public function keepsOnlyTheTypeListWhenTheObjectCarriesNothingElse(): void
    {
        // The selection is opt-in: a bare object must not gain null-valued keys.
        $row = $this->mapper->map($this->object(['CulturalSite', 'Museum']));

        self::assertNotNull($row);
        self::assertSame(['type' => ['CulturalSite', 'Museum']], $row['tags']);
        self::assertNull($row['website']);
        self::assertNull($row['openingHours']);
    }

    #[Test]
    public function fallsBackToTheBookingContactForTheWebsite(): void
    {
        $row = $this->mapper->map($this->object(['Accommodation', 'Hotel'], [
            'hasBookingContact' => [['@type' => ['Agent'], 'foaf:homepage' => ['https://booking.test/hotel']]],
        ]));

        self::assertNotNull($row);
        self::assertSame('https://booking.test/hotel', $row['website']);
    }

    #[Test]
    public function usesTheTopLevelHomepageAsTheOfficialWebsite(): void
    {
        $row = $this->mapper->map($this->object(['EntertainmentAndEvent', 'Festival'], [
            'foaf:homepage' => ['https://festival.test'],
            'hasContact' => [['@type' => ['Agent'], 'foaf:homepage' => ['https://organiser.test']]],
        ]));

        self::assertNotNull($row);
        self::assertSame('https://festival.test', $row['website']);
    }

    /**
     * The flux publishes one OpeningHoursSpecification per period; the row keeps
     * their envelope in the OSM-ish syntax SeasonalityChecker parses.
     */
    #[Test]
    public function mapsTheOpeningSpecificationsToASeasonString(): void
    {
        $row = $this->mapper->map($this->object(['Accommodation', 'Camping'], [
            'isLocatedAt' => [[
                '@type' => ['PlaceOfInterest'],
                'schema:geo' => ['schema:latitude' => '49.1', 'schema:longitude' => '7.13'],
                'schema:openingHoursSpecification' => [
                    [
                        '@type' => ['schema:OpeningHoursSpecification'],
                        'schema:validFrom' => '2026-04-15',
                        'schema:validThrough' => '2026-06-30',
                        'schema:opens' => '09:00:00',
                        'schema:closes' => '18:00:00',
                    ],
                    [
                        '@type' => ['schema:OpeningHoursSpecification'],
                        'schema:validFrom' => '2026-07-01',
                        'schema:validThrough' => '2026-10-31',
                        'schema:opens' => '08:00:00',
                        'schema:closes' => '20:00:00',
                    ],
                ],
            ]],
        ]));

        self::assertNotNull($row);
        self::assertSame('Apr-Oct 08:00-20:00', $row['openingHours']);
        self::assertSame('Apr-Oct 08:00-20:00', $row['tags']['opening_hours']);
    }

    #[Test]
    public function readsTheDataTourismeNamingOfTheOpeningSpecifications(): void
    {
        // takesPlaceAt uses startDate/endDate rather than schema:validFrom/Through.
        $row = $this->mapper->map($this->object(['CulturalSite', 'Museum'], [
            'takesPlaceAt' => [[
                '@type' => ['OpeningHoursSpecification'],
                'startDate' => '2026-05-01',
                'endDate' => '2026-09-30',
            ]],
        ]));

        self::assertNotNull($row);
        self::assertSame('May-Sep', $row['openingHours']);
    }

    #[Test]
    public function ignoresOpeningSpecificationsWithoutUsableDatesOrTimes(): void
    {
        $row = $this->mapper->map($this->object(['CulturalSite', 'Museum'], [
            'takesPlaceAt' => [['@type' => ['OpeningHoursSpecification'], 'schema:dayOfWeek' => ['Monday']]],
        ]));

        self::assertNotNull($row);
        self::assertNull($row['openingHours']);
        self::assertArrayNotHasKey('opening_hours', $row['tags']);
    }

    /**
     * tourism.accommodations gained website / phone / opening_hours / wikidata
     * columns (#872), so the row must carry each of them, not just the tags.
     */
    #[Test]
    public function exposesTheAccommodationContactAndQidAsRowFields(): void
    {
        $row = $this->mapper->map($this->object(['Accommodation', 'Camping'], [
            'owl:sameAs' => ['https://www.wikidata.org/entity/Q1234'],
            'hasContact' => [[
                '@type' => ['Agent'],
                'foaf:homepage' => ['https://camping.test'],
                'schema:telephone' => ['+33 3 88 00 00 00'],
            ]],
            'takesPlaceAt' => [[
                '@type' => ['OpeningHoursSpecification'],
                'startDate' => '2026-04-01',
                'endDate' => '2026-10-31',
            ]],
        ]));

        self::assertNotNull($row);
        self::assertSame('https://camping.test', $row['website']);
        self::assertSame('+33 3 88 00 00 00', $row['phone']);
        self::assertSame('Apr-Oct', $row['openingHours']);
        self::assertSame('Q1234', $row['wikidata']);
    }

    /**
     * A schema-less homepage — what an office de tourisme types most often — is
     * absolutised, otherwise the browser resolves it against the app origin.
     */
    #[Test]
    public function absolutisesASchemaLessHomepage(): void
    {
        $row = $this->mapper->map($this->object(['Accommodation', 'Hotel'], [
            'foaf:homepage' => ['www.Hotel-Du-Parc.fr/chambres?ref=dt'],
        ]));

        self::assertNotNull($row);
        self::assertSame('https://www.hotel-du-parc.fr/chambres?ref=dt', $row['website']);
        self::assertSame('https://www.hotel-du-parc.fr/chambres?ref=dt', $row['tags']['website']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableHomepages(): array
    {
        return [
            'free text' => ['nous contacter'],
            'e-mail address' => ['contact@gite.test'],
            'mailto scheme' => ['mailto:contact@gite.test'],
            'tel scheme' => ['tel:+33388000000'],
            'script scheme' => ['javascript:alert(1)'],
            'bare word' => ['gite'],
        ];
    }

    #[Test]
    #[DataProvider('unusableHomepages')]
    public function dropsAHomepageThatIsNotAUsableUrl(string $homepage): void
    {
        $row = $this->mapper->map($this->object(['Accommodation', 'Hotel'], [
            'foaf:homepage' => [$homepage],
        ]));

        self::assertNotNull($row);
        self::assertNull($row['website'], 'an unusable value is stored NULL rather than as is');
        self::assertArrayNotHasKey('website', $row['tags']);
    }

    #[Test]
    public function fallsBackToTheNextHomepageWhenTheFirstIsUnusable(): void
    {
        // A rejected top-level homepage must not shadow a usable contact one.
        $row = $this->mapper->map($this->object(['Accommodation', 'Hotel'], [
            'foaf:homepage' => ['nous contacter'],
            'hasContact' => [['@type' => ['Agent'], 'foaf:homepage' => ['hotel-du-parc.fr']]],
        ]));

        self::assertNotNull($row);
        self::assertSame('https://hotel-du-parc.fr', $row['website']);
    }
}
