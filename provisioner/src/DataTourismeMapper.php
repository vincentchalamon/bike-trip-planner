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
 * runtime REST API shape, so this is a flux-specific mapper. The raw type list
 * is preserved in the row's tags so nothing is lost.
 *
 * @phpstan-type Row array{head: 'cultural'|'accommodation'|'event', id: string, name: string|null, category: string, lat: float, lon: float, description: string|null, openingHours: string|null, wikidata: string|null, capacity: int|null, price: float|null, startDate: string|null, endDate: string|null, type: list<string>}
 */
final class DataTourismeMapper
{
    /** Accommodation subtype (unprefixed ontology type) → app accommodation category. */
    private const array ACCOMMODATION_CATEGORY = [
        'Hotel' => 'hotel', 'HotelTrade' => 'hotel', 'HotelRestaurant' => 'hotel',
        'Guesthouse' => 'guest_house', 'TableHoteGuesthouse' => 'guest_house', 'BedAndBreakfast' => 'guest_house',
        'Camping' => 'camp_site', 'CampingAndCaravanning' => 'camp_site', 'NaturalCampingArea' => 'camp_site',
        'FarmCamping' => 'camp_site', 'CamperVanArea' => 'camp_site', 'CampingCar' => 'camp_site',
        'Chalet' => 'chalet', 'Hut' => 'wilderness_hut', 'TreeHouse' => 'chalet',
        'CollectiveAccommodation' => 'hostel', 'GroupLodging' => 'hostel', 'StopOverOrGroupLodge' => 'hostel',
        'ClubOrHolidayVillage' => 'hostel', 'HolidayResort' => 'hostel',
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

    /** Event subtype (unprefixed ontology type) → app event category. */
    private const array EVENT_CATEGORY = [
        'Festival' => 'festival', 'TraditionalCelebration' => 'festival', 'Carnival' => 'festival', 'Parade' => 'festival',
        'Concert' => 'concert', 'Recital' => 'concert', 'Opera' => 'concert',
        'Exhibition' => 'exhibition', 'VisualArtsEvent' => 'exhibition',
        'SportsEvent' => 'sports', 'SportsCompetition' => 'sports', 'SportsDemonstration' => 'sports',
        'FairOrShow' => 'fair', 'SaleEvent' => 'fair', 'BusinessEvent' => 'fair', 'OpenDay' => 'fair',
        'TheaterEvent' => 'show', 'ShowEvent' => 'show', 'ScreeningEvent' => 'show', 'Cinema' => 'show',
    ];

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

        return [
            'head' => $head,
            'id' => $id,
            'name' => $this->label($object['rdfs:label'] ?? null),
            'category' => $category,
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
            'description' => $this->description($object),
            'openingHours' => null,
            'wikidata' => $this->wikidata($object),
            'capacity' => 'accommodation' === $head ? $this->intOrNull($object['allowedPersons'] ?? null) : null,
            'price' => 'accommodation' === $head || 'event' === $head ? $this->price($object['offers'] ?? null) : null,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'type' => $types,
        ];
    }

    /**
     * @param list<string> $types
     *
     * @return array{'cultural'|'accommodation'|'event'|null, string}
     */
    private function classify(array $types): array
    {
        // Events first: an event venue can also carry place types.
        if (\in_array('EntertainmentAndEvent', $types, true)) {
            return ['event', $this->resolve($types, self::EVENT_CATEGORY, 'event')];
        }

        if (\in_array('Accommodation', $types, true)) {
            return ['accommodation', $this->resolve($types, self::ACCOMMODATION_CATEGORY, 'apartment')];
        }

        if (\in_array('CulturalSite', $types, true) || \in_array('NaturalHeritage', $types, true)) {
            return ['cultural', $this->resolve($types, self::CULTURAL_CATEGORY, 'attraction')];
        }

        return [null, ''];
    }

    /**
     * @param list<string>          $types
     * @param array<string, string> $map
     */
    private function resolve(array $types, array $map, string $default): string
    {
        foreach ($types as $type) {
            if (isset($map[$type])) {
                return $map[$type];
            }
        }

        return $default;
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
