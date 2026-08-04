<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Maps one DataTourisme flux JSON-LD object to a normalised row for a tourism.*
 * table, or null when it is not a category we import.
 *
 * The flux serialises the DataTourisme ontology with UNPREFIXED type terms in
 * the JSON-LD type array (e.g. "CulturalSite", "Accommodation",
 * "EntertainmentAndEvent"), labels as language-maps ({"fr":["…"]}), and the
 * location under isLocatedAt[0]["schema:geo"] — none of which match the old
 * runtime REST API shape, so this is a flux-specific mapper. A curated subset of
 * the source object is preserved in the row's tags (see {@see tags}), because
 * whatever is dropped here is unrecoverable short of a full re-import (#871).
 *
 * @phpstan-type Row array{head: 'cultural'|'accommodation'|'event'|'food', id: string, name: string|null, category: string, lat: float, lon: float, description: string|null, openingHours: string|null, website: string|null, phone: string|null, wikidata: string|null, capacity: int|null, price: float|null, startDate: string|null, endDate: string|null, tags: array<string, mixed>}
 */
final class DataTourismeMapper
{
    /**
     * Accommodation subtype (unprefixed ontology type) → app accommodation
     * category. Every category MUST exist in TripRequest::ALL_ACCOMMODATION_TYPES:
     * the accommodation repositories filter on `category IN (:categories)`, so a
     * category outside that vocabulary can never be read back.
     *
     * `AccommodationProduct` is deliberately absent: it is a commercial offer,
     * not a place. In the flux it only ever co-occurs with a place subtype (which
     * is what classifies the object), so it never needs a category of its own.
     */
    private const array ACCOMMODATION_CATEGORY = [
        'Hotel' => 'hotel', 'HotelTrade' => 'hotel', 'HotelRestaurant' => 'hotel',
        'Guesthouse' => 'guest_house', 'TableHoteGuesthouse' => 'guest_house', 'BedAndBreakfast' => 'guest_house',
        'Camping' => 'camp_site', 'CampingAndCaravanning' => 'camp_site', 'NaturalCampingArea' => 'camp_site',
        'FarmCamping' => 'camp_site', 'CamperVanArea' => 'camp_site', 'CampingCar' => 'camp_site',
        'Chalet' => 'chalet', 'Hut' => 'wilderness_hut', 'TreeHouse' => 'chalet',
        'CollectiveAccommodation' => 'hostel', 'GroupLodging' => 'hostel', 'StopOverOrGroupLodge' => 'hostel',
        'ClubOrHolidayVillage' => 'hostel', 'HolidayResort' => 'hostel',
        // The French "meublé de tourisme" subtypes (RentalAccommodation,
        // SelfCateringAccommodation, House, Apartment, Bungalow, Yurt,
        // CastleAndPrestigeMansion) are deliberately absent since #927: that market
        // is let by the week and the flux carries no minimum-stay predicate
        // (docs/datatourisme-flux-audit.md), so it cannot be offered for a single
        // night. They now fall into unmappedAccommodationCount(), which is expected
        // to be large — they are two thirds of the accommodation objects.
    ];

    /** Cultural/natural subtype (unprefixed ontology type) → app cultural-POI category. */
    private const array CULTURAL_CATEGORY = [
        'Museum' => 'museum', 'InterpretationCentre' => 'museum', 'ArtGalleryOrExhibitionGallery' => 'museum',
        'Castle' => 'monument', 'FortifiedCastle' => 'monument', 'Fort' => 'monument', 'DefenceSite' => 'monument',
        'ReligiousSite' => 'monument', 'Church' => 'monument', 'Cathedral' => 'monument', 'Chapel' => 'monument',
        'Abbey' => 'monument', 'Basilica' => 'monument', 'Cloister' => 'monument', 'Calvary' => 'monument',
        'RemembranceSite' => 'monument', 'Commemoration' => 'monument', 'ArcheologicalSite' => 'monument',
        'RemarkableBuilding' => 'monument', 'CityHeritage' => 'monument', 'TechnicalHeritage' => 'monument',
        'IndustrialSite' => 'monument', 'Mill' => 'monument', 'Aqueduct' => 'monument', 'Bridge' => 'monument',
        'PointOfView' => 'viewpoint', 'NaturalHeritage' => 'viewpoint', 'NaturalCuriosity' => 'viewpoint',
        'ParkAndGarden' => 'viewpoint', 'Forest' => 'viewpoint', 'Beach' => 'viewpoint', 'Lake' => 'viewpoint',
        'Dune' => 'viewpoint', 'Glacier' => 'viewpoint', 'CaveSinkholeOrAven' => 'viewpoint', 'Source' => 'viewpoint',
    ];

    /**
     * Food establishment / food shop subtype (unprefixed ontology type) → app
     * resupply category (must stay within ScanPoisHandler::RESUPPLY_CATEGORIES).
     */
    private const array FOOD_CATEGORY = [
        'FastFoodRestaurant' => 'fast_food', 'StreetFood' => 'fast_food',
        'BarOrPub' => 'bar', 'BistroOrWineBar' => 'bar',
        'CafeOrTeahouse' => 'cafe',
        'Bakery' => 'bakery',
        'Restaurant' => 'restaurant', 'GourmetRestaurant' => 'restaurant',
        'BrasserieOrTavern' => 'restaurant', 'FarmhouseInn' => 'restaurant',
        'LocalProductsShop' => 'farm',
    ];

    /**
     * Store subtypes that are food/resupply relevant. A `Store` without one of
     * these is skipped: DataTourisme tags many non-food businesses as Store
     * (boutiques, craftsmen, parking, taxis, tourist offices), which carry no
     * resupply value and would pollute the supply timeline.
     */
    private const array FOOD_STORE_TYPES = ['Bakery', 'LocalProductsShop'];

    /** Event subtype (unprefixed ontology type) → app event category. */
    private const array EVENT_CATEGORY = [
        'Festival' => 'festival', 'TraditionalCelebration' => 'festival', 'Carnival' => 'festival', 'Parade' => 'festival',
        'Concert' => 'concert', 'Recital' => 'concert', 'Opera' => 'concert',
        'Exhibition' => 'exhibition', 'VisualArtsEvent' => 'exhibition',
        'SportsEvent' => 'sports', 'SportsCompetition' => 'sports', 'SportsDemonstration' => 'sports',
        'FairOrShow' => 'fair', 'SaleEvent' => 'fair', 'BusinessEvent' => 'fair', 'OpenDay' => 'fair',
        'TheaterEvent' => 'show', 'ShowEvent' => 'show', 'ScreeningEvent' => 'show', 'Cinema' => 'show',
    ];

    /** English 3-letter month abbreviations, the vocabulary SeasonalityChecker parses. */
    private const array MONTH_ABBREVIATIONS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    private int $unmappedAccommodations = 0;

    /**
     * Accommodation objects dropped because no subtype of theirs is mapped to an
     * app category. Surfaced by the provisioning command so the next unknown
     * ontology type is a visible number instead of a silently polluted bucket.
     */
    public function unmappedAccommodationCount(): int
    {
        return $this->unmappedAccommodations;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @phpstan-return Row|null
     */
    public function map(array $object): ?array
    {
        $types = $this->types($object);
        if ([] === $types) {
            return null;
        }

        $id = \is_string($object['@id'] ?? null) ? $object['@id'] : null;
        if (null === $id) {
            return null;
        }

        $coords = $this->coordinates($object);
        if (null === $coords) {
            return null;
        }

        [$head, $category] = $this->classify($types);
        if (null === $head) {
            return null;
        }

        [$startDate, $endDate] = 'event' === $head ? $this->dates($object) : [null, null];
        $contact = $this->contact($object);
        $openingHours = $this->openingHours($object);

        return [
            'head' => $head,
            'id' => $id,
            'name' => $this->label($object['rdfs:label'] ?? null),
            'category' => $category,
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
            'description' => $this->description($object),
            'openingHours' => $openingHours,
            'website' => $contact['website'] ?? $contact['bookingUrl'],
            'phone' => $contact['phone'],
            'wikidata' => $this->wikidata($object),
            'capacity' => 'accommodation' === $head ? $this->intOrNull($object['allowedPersons'] ?? null) : null,
            'price' => 'accommodation' === $head || 'event' === $head ? $this->price($object['offers'] ?? null) : null,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'tags' => $this->tags($object, $types, $openingHours, $contact),
        ];
    }

    /**
     * Flux keys preserved in the row's `tags` jsonb.
     *
     * The flux object carries ~40 predicates; storing them wholesale would turn a
     * 4-column index into an archive of the source dump. The selection below is
     * explicit and each key has a named consumer:
     *
     * - `type`: the raw ontology type list, the only way to reclassify a row (a
     *   new subtype, a category split) without re-importing the whole flux.
     * - `opening_hours`: OSM tag key on purpose, so `App\Accommodation\SeasonalityChecker`
     *   — which reads `$tags['opening_hours']` — works on DataTourisme rows too.
     * - `website`, `phone`, `email`, `booking_url`: the contact block, feeding the
     *   accommodation URL and the `hasWebsite` completeness signal (#869). `website`
     *   and `phone` are also columns since #872; they stay here as the fallback the
     *   read path uses for rows imported before that, and `booking_url` / `email`
     *   have no column of their own.
     * - `address`, `postal_code`, `city`: the postal address, so a stage label or a
     *   suggestion can be shown without a reverse-geocode round trip.
     * - `image_url`: the main photo. It stays a tag rather than becoming a column:
     *   the `image_url` column is Wikidata-only across every table (the shared
     *   {@see WikidataEnrichmentPass} overwrites it), so a flux photo written there
     *   would be erased for any row carrying a Q-ID.
     * - `labels`: classification / feature labels, where quality labels such as
     *   "Accueil Vélo" live.
     *
     * Deliberately dropped: the long descriptions and reviews (kilobytes of free
     * text per row across ~390k objects, no consumer), the themes and audiences,
     * the media list beyond the main photo, and the publication metadata. Keys with
     * no value are omitted rather than stored as null, so a bare object still
     * weighs exactly its type list.
     *
     * @param array<string, mixed>                                                         $object
     * @param list<string>                                                                 $types
     * @param array{website: ?string, phone: ?string, email: ?string, bookingUrl: ?string} $contact
     *
     * @return array<string, mixed>
     */
    private function tags(array $object, array $types, ?string $openingHours, array $contact): array
    {
        $address = $this->address($object);

        return array_filter([
            'type' => $types,
            'opening_hours' => $openingHours,
            'website' => $contact['website'],
            'phone' => $contact['phone'],
            'email' => $contact['email'],
            'booking_url' => $contact['bookingUrl'],
            'address' => $address['address'],
            'postal_code' => $address['postalCode'],
            'city' => $address['city'],
            'image_url' => $this->imageUrl($object),
            'labels' => $this->labels($object),
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * @param list<string> $types
     *
     * @return array{'cultural'|'accommodation'|'event'|'food'|null, string}
     */
    private function classify(array $types): array
    {
        // Events first: an event venue can also carry place types.
        if (\in_array('EntertainmentAndEvent', $types, true)) {
            return ['event', $this->resolve($types, self::EVENT_CATEGORY) ?? 'event'];
        }

        // Accommodation before food: a HotelRestaurant is primarily lodging.
        if (\in_array('Accommodation', $types, true)) {
            // No default here: a fallback category outside the app vocabulary
            // makes the row permanently unreadable (issue #865). An unmapped
            // subtype is dropped and counted instead.
            $category = $this->resolve($types, self::ACCOMMODATION_CATEGORY);
            if (null === $category) {
                ++$this->unmappedAccommodations;

                return [null, ''];
            }

            return ['accommodation', $category];
        }

        if (\in_array('CulturalSite', $types, true) || \in_array('NaturalHeritage', $types, true)) {
            return ['cultural', $this->resolve($types, self::CULTURAL_CATEGORY) ?? 'attraction'];
        }

        // Eateries (any FoodEstablishment) and food shops (Store with a food subtype).
        if (\in_array('FoodEstablishment', $types, true)) {
            return ['food', $this->resolve($types, self::FOOD_CATEGORY) ?? 'restaurant'];
        }

        if (\in_array('Store', $types, true) && [] !== array_intersect(self::FOOD_STORE_TYPES, $types)) {
            return ['food', $this->resolve($types, self::FOOD_CATEGORY) ?? 'general'];
        }

        return [null, ''];
    }

    /**
     * @param list<string>          $types
     * @param array<string, string> $map
     */
    private function resolve(array $types, array $map): ?string
    {
        foreach ($types as $type) {
            if (isset($map[$type])) {
                return $map[$type];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return list<string>
     */
    private function types(array $object): array
    {
        $raw = $object['@type'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, is_string(...)));
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array{lat: float, lon: float}|null
     */
    private function coordinates(array $object): ?array
    {
        $located = $object['isLocatedAt'] ?? null;
        $first = \is_array($located) ? ($located[0] ?? null) : null;
        $geo = \is_array($first) ? ($first['schema:geo'] ?? null) : null;
        if (!\is_array($geo)) {
            return null;
        }

        $lat = $geo['schema:latitude'] ?? null;
        $lon = $geo['schema:longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lon' => (float) $lon];
    }

    /**
     * First value of a DataTourisme language-map ({"fr":["…"],"en":[…]}),
     * preferring fr then en then any language.
     */
    private function label(mixed $langMap): ?string
    {
        if (!\is_array($langMap)) {
            return null;
        }

        foreach (['fr', 'en'] as $lang) {
            $value = $this->firstString($langMap[$lang] ?? null);
            if (null !== $value) {
                return $value;
            }
        }

        foreach ($langMap as $values) {
            $value = $this->firstString($values);
            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function description(array $object): ?string
    {
        $hasDescription = $object['hasDescription'] ?? null;
        $first = \is_array($hasDescription) ? ($hasDescription[0] ?? null) : null;
        if (\is_array($first)) {
            $short = $this->label($first['shortDescription'] ?? null);
            if (null !== $short) {
                return $short;
            }
        }

        return $this->label($object['rdfs:comment'] ?? null);
    }

    /**
     * Contact block: the official homepage plus the phone/email of the first
     * contact carrying them, and the booking contact's homepage. The flux splits
     * them over `foaf:homepage` (top level), `hasContact` and `hasBookingContact`;
     * the first non-empty value wins, contacts being published most-relevant first.
     *
     * The homepages go through {@see WebsiteUrl}: they are hand-typed, so a
     * schema-less "www.gite.fr" is absolutised and an unusable value ("nous
     * contacter", an e-mail address) becomes null rather than being stored as is
     * (#872). A homepage rejected there does not disqualify the contact — the
     * next one is still considered, exactly as an absent homepage would be.
     *
     * @param array<string, mixed> $object
     *
     * @return array{website: ?string, phone: ?string, email: ?string, bookingUrl: ?string}
     */
    private function contact(array $object): array
    {
        $website = WebsiteUrl::normalize($this->firstString($object['foaf:homepage'] ?? null));
        $phone = null;
        $email = null;
        foreach ($this->objectList($object['hasContact'] ?? null) as $contact) {
            $website ??= WebsiteUrl::normalize($this->firstString($contact['foaf:homepage'] ?? null));
            $phone ??= $this->firstString($contact['schema:telephone'] ?? null);
            $email ??= $this->firstString($contact['schema:email'] ?? null);
        }

        $bookingUrl = null;
        foreach ($this->objectList($object['hasBookingContact'] ?? null) as $contact) {
            $bookingUrl ??= WebsiteUrl::normalize($this->firstString($contact['foaf:homepage'] ?? null));
        }

        return ['website' => $website, 'phone' => $phone, 'email' => $email, 'bookingUrl' => $bookingUrl];
    }

    /**
     * Postal address of the located place. The city is published either inline
     * (`schema:addressLocality`) or as a linked City resource (`hasAddressCity`).
     *
     * @param array<string, mixed> $object
     *
     * @return array{address: ?string, postalCode: ?string, city: ?string}
     */
    private function address(array $object): array
    {
        $located = $this->objectList($object['isLocatedAt'] ?? null)[0] ?? [];
        $address = $this->objectList($located['schema:address'] ?? null)[0] ?? [];

        $city = $this->firstString($address['schema:addressLocality'] ?? null);
        if (null === $city) {
            $linkedCity = $this->objectList($address['hasAddressCity'] ?? null)[0] ?? [];
            $city = $this->label($linkedCity['rdfs:label'] ?? null);
        }

        return [
            'address' => $this->firstString($address['schema:streetAddress'] ?? null),
            'postalCode' => $this->firstString($address['schema:postalCode'] ?? null),
            'city' => $city,
        ];
    }

    /**
     * Opening hours as a single OSM-flavoured string ("Apr-Oct 09:00-18:00").
     *
     * The flux publishes one `OpeningHoursSpecification` per period, under
     * `takesPlaceAt` or under the place's `schema:openingHoursSpecification`, with
     * either the schema.org (`schema:validFrom`) or the DataTourisme (`startDate`)
     * naming. Nothing downstream can consume that list, so it is reduced to its
     * envelope: earliest month → latest month, earliest opening → latest closing.
     * That is the syntax {@see \App\Accommodation\SeasonalityChecker} parses, and
     * the one already displayed for the OSM and Wikidata rows.
     *
     * @param array<string, mixed> $object
     */
    private function openingHours(array $object): ?string
    {
        $located = $this->objectList($object['isLocatedAt'] ?? null)[0] ?? [];
        $specifications = array_merge(
            $this->objectList($object['takesPlaceAt'] ?? null),
            $this->objectList($object['schema:openingHoursSpecification'] ?? null),
            $this->objectList($located['schema:openingHoursSpecification'] ?? null),
        );

        $firstMonth = null;
        $lastMonth = null;
        $opensAt = null;
        $closesAt = null;
        foreach ($specifications as $specification) {
            $from = $this->month($specification['schema:validFrom'] ?? $specification['startDate'] ?? $specification['schema:startDate'] ?? null);
            $through = $this->month($specification['schema:validThrough'] ?? $specification['endDate'] ?? $specification['schema:endDate'] ?? null);
            if (null !== $from && null !== $through) {
                $firstMonth = null === $firstMonth ? $from : min($firstMonth, $from);
                $lastMonth = null === $lastMonth ? $through : max($lastMonth, $through);
            }

            $open = $this->time($specification['schema:opens'] ?? $specification['startTime'] ?? $specification['schema:startTime'] ?? null);
            $close = $this->time($specification['schema:closes'] ?? $specification['endTime'] ?? $specification['schema:endTime'] ?? null);
            if (null !== $open && null !== $close) {
                $opensAt = null === $opensAt ? $open : min($opensAt, $open);
                $closesAt = null === $closesAt ? $close : max($closesAt, $close);
            }
        }

        $parts = [];
        if (null !== $firstMonth && null !== $lastMonth) {
            $parts[] = \sprintf('%s-%s', self::MONTH_ABBREVIATIONS[$firstMonth], self::MONTH_ABBREVIATIONS[$lastMonth]);
        }

        if (null !== $opensAt && null !== $closesAt) {
            $parts[] = \sprintf('%s-%s', $opensAt, $closesAt);
        }

        return [] === $parts ? null : implode(' ', $parts);
    }

    /**
     * Main photo URL (hasMainRepresentation → MediaResource → ebucore:locator).
     *
     * @param array<string, mixed> $object
     */
    private function imageUrl(array $object): ?string
    {
        foreach ($this->objectList($object['hasMainRepresentation'] ?? null) as $representation) {
            foreach ($this->objectList($representation['ebucore:hasRelatedResource'] ?? null) as $resource) {
                $locator = WebsiteUrl::normalize($this->firstString($resource['ebucore:locator'] ?? null));
                if (null !== $locator) {
                    return $locator;
                }
            }
        }

        return null;
    }

    /**
     * Classification, feature and theme labels, deduplicated. Quality labels such
     * as "Accueil Vélo" are published as classifications.
     *
     * @param array<string, mixed> $object
     *
     * @return list<string>
     */
    private function labels(array $object): array
    {
        $labels = [];
        foreach (['hasClassification', 'hasFeature', 'hasTheme'] as $predicate) {
            foreach ($this->objectList($object[$predicate] ?? null) as $entry) {
                $label = $this->label($entry['rdfs:label'] ?? null);
                if (null !== $label) {
                    $labels[] = $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Nested JSON-LD resources, which the flux publishes either as a list or, when
     * there is a single one, as the bare object.
     *
     * @return list<array<string, mixed>>
     */
    private function objectList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        if (isset($value['@id']) || isset($value['@type'])) {
            return [$this->stringKeyed($value)];
        }

        $objects = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $objects[] = $this->stringKeyed($item);
            }
        }

        return $objects;
    }

    /**
     * @param array<mixed, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $keyed = [];
        foreach ($value as $key => $property) {
            $keyed[(string) $key] = $property;
        }

        return $keyed;
    }

    /** Month number of an ISO date ("2026-04-01" → 4). */
    private function month(mixed $value): ?int
    {
        $date = $this->firstString($value);

        return null !== $date && 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])/', $date, $matches) ? (int) $matches[1] : null;
    }

    /** HH:MM of an ISO time ("09:00:00" → "09:00"). */
    private function time(mixed $value): ?string
    {
        $time = $this->firstString($value);

        return null !== $time && 1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $time) ? substr($time, 0, 5) : null;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function wikidata(array $object): ?string
    {
        $sameAs = $object['owl:sameAs'] ?? null;
        $uris = \is_array($sameAs) ? $sameAs : (\is_string($sameAs) ? [$sameAs] : []);
        foreach ($uris as $uri) {
            if (\is_string($uri) && str_contains($uri, 'wikidata.org/entity/')) {
                $id = substr($uri, strrpos($uri, '/') + 1);
                if (1 === preg_match('/^Q\d+$/', $id)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array{string|null, string|null}
     */
    private function dates(array $object): array
    {
        return [
            $this->firstString($object['schema:startDate'] ?? null),
            $this->firstString($object['schema:endDate'] ?? null),
        ];
    }

    private function price(mixed $offers): ?float
    {
        if (!\is_array($offers)) {
            return null;
        }

        foreach ($offers as $offer) {
            if (!\is_array($offer)) {
                continue;
            }

            $specs = $offer['schema:priceSpecification'] ?? $offer['priceSpecification'] ?? null;
            $specs = \is_array($specs) ? $specs : [];
            foreach ($specs as $spec) {
                $price = \is_array($spec) ? ($spec['schema:price'] ?? $spec['price'] ?? null) : null;
                if (is_numeric($price)) {
                    return (float) $price;
                }
            }
        }

        return null;
    }

    private function firstString(mixed $value): ?string
    {
        if (\is_string($value)) {
            return '' === $value ? null : $value;
        }

        if (\is_array($value) && \is_string($value[0] ?? null) && '' !== $value[0]) {
            return $value[0];
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
