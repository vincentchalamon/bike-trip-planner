<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Maps one OpenAgenda record (Opendatasoft "evenements-publics-openagenda"
 * dataset, JSONL export) to a normalised `tourism.events` row, or null when it is
 * not an event worth importing.
 *
 * OpenAgenda is national, published under Licence Ouverte, with precise geo, a date
 * range and a canonical URL on every record. Two rules drop a record here rather
 * than later:
 *
 * - **A link is mandatory.** An event a rider cannot open is noise (ADR-051); a
 *   record without a `canonicalurl` is skipped, mirroring the DataTourisme mapper.
 * - **A usable date range is mandatory.** The events read path matches
 *   `start_date <= day <= end_date`; an undated record can never match a stage day.
 *
 * The category is normalised onto the **same** app vocabulary the DataTourisme
 * mapper produces (`festival`, `concert`, `exhibition`, `sports`, `fair`, `show`),
 * so the shared relevance whitelist in `App\EventSource\EventSourceRegistry` filters
 * both sources with one rule. OpenAgenda has no category taxonomy, only free
 * keywords, so the mapping is keyword-based, with a generic `event` fallback that
 * the whitelist drops. Young-audience records are normalised to `youth`, a category
 * deliberately outside the whitelist: that is how the shared audience filter of
 * ADR-051 §3 drops them without a per-source relevance branch.
 *
 * @phpstan-type Row array{id: string, name: string|null, category: string, lat: float, lon: float, startDate: string, endDate: string, url: string, description: string|null, priceMin: float|null, tags: array<string, mixed>}
 */
final class OpenAgendaMapper
{
    /**
     * Normalised keyword substring → app event category, matching the DataTourisme
     * mapper's EVENT_CATEGORY targets. Scanned in order; the first key contained in a
     * record keyword wins, so more specific terms come before generic ones.
     */
    private const array KEYWORD_CATEGORY = [
        'festival' => 'festival',
        'carnaval' => 'festival',
        'fete' => 'festival',
        'exposition' => 'exhibition',
        'vernissage' => 'exhibition',
        'concert' => 'concert',
        'musique' => 'concert',
        'recital' => 'concert',
        'opera' => 'concert',
        'sport' => 'sports',
        'course' => 'sports',
        'randonnee' => 'sports',
        'competition' => 'sports',
        'foire' => 'fair',
        'salon' => 'fair',
        'brocante' => 'fair',
        'marche' => 'fair',
        'spectacle' => 'show',
        'theatre' => 'show',
        'danse' => 'show',
        'cinema' => 'show',
        'projection' => 'show',
        'cirque' => 'show',
    ];

    /**
     * Young-audience markers. A record carrying one is mapped to `youth`, which the
     * relevance whitelist drops (ADR-051 §3): a bikepacker does not reroute for a
     * children's activity. Audience takes precedence over the topic keywords above.
     */
    private const array YOUTH_KEYWORDS = ['jeune public', 'jeunesse', 'enfant', 'petite enfance', 'bebe'];

    /**
     * @param array<string, mixed> $record
     *
     * @phpstan-return Row|null
     */
    public function map(array $record): ?array
    {
        $url = $this->firstString($record['canonicalurl'] ?? null);
        if (null === $url) {
            return null;
        }

        $uid = $record['uid'] ?? null;
        if (!\is_string($uid) && !\is_int($uid)) {
            return null;
        }

        $coords = $this->coordinates($record['location_coordinates'] ?? null);
        if (null === $coords) {
            return null;
        }

        $startDate = $this->date($record['firstdate_begin'] ?? $record['date_start'] ?? null);
        $endDate = $this->date($record['lastdate_end'] ?? $record['date_end'] ?? null);
        if (null === $startDate || null === $endDate) {
            return null;
        }

        return [
            'id' => 'openagenda:'.$uid,
            'name' => $this->firstString($record['title_fr'] ?? null),
            'category' => $this->category($record),
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'url' => $url,
            'description' => $this->firstString($record['description_fr'] ?? $record['longdescription_fr'] ?? null),
            // The dataset carries no reliable numeric admission price; left null.
            'priceMin' => null,
            'tags' => $this->tags($record),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function category(array $record): string
    {
        $keywords = array_map($this->normalize(...), $this->keywords($record));
        $haystack = implode(' ', $keywords);

        foreach (self::YOUTH_KEYWORDS as $marker) {
            if ($this->containsWord($haystack, $marker)) {
                return 'youth';
            }
        }

        // An explicit children-only age ceiling is a young-audience signal too.
        $ageMax = $record['age_max'] ?? null;
        if (is_numeric($ageMax) && (int) $ageMax > 0 && (int) $ageMax <= 12) {
            return 'youth';
        }

        foreach (self::KEYWORD_CATEGORY as $needle => $category) {
            if ($this->containsWord($haystack, $needle)) {
                return $category;
            }
        }

        return 'event';
    }

    /**
     * Whole-word match on the space-joined keyword haystack. A bare
     * `str_contains` would misclassify words that merely embed a needle —
     * "transport" contains "sport", "demarche" contains "marche" — so matching
     * is anchored to word boundaries.
     */
    private function containsWord(string $haystack, string $needle): bool
    {
        return 1 === preg_match('/\b'.preg_quote($needle, '/').'\b/u', $haystack);
    }

    /**
     * Curated subset preserved in the row's `tags` jsonb, so a stage label or a
     * reclassification survives without a re-import. Keys with no value are omitted.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function tags(array $record): array
    {
        return array_filter([
            'keywords' => array_values($this->keywords($record)),
            'city' => $this->firstString($record['location_city'] ?? null),
            'postal_code' => $this->firstString($record['location_postalcode'] ?? null),
            'address' => $this->firstString($record['location_address'] ?? null),
            'region' => $this->firstString($record['location_region'] ?? null),
            'image_url' => $this->firstString($record['image'] ?? $record['thumbnail'] ?? null),
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private function keywords(array $record): array
    {
        $raw = $record['keywords_fr'] ?? $record['keywords'] ?? null;
        if (\is_string($raw)) {
            $raw = ['' === $raw ? null : $raw];
        }

        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn (mixed $v): bool => \is_string($v) && '' !== $v));
    }

    /**
     * Coordinates from an Opendatasoft geo point, published either as `{lon, lat}`
     * (v2.1 JSONL) or as a `[lat, lon]` pair.
     *
     * @return array{lat: float, lon: float}|null
     */
    private function coordinates(mixed $value): ?array
    {
        if (\is_array($value) && isset($value['lat'], $value['lon']) && is_numeric($value['lat']) && is_numeric($value['lon'])) {
            return ['lat' => (float) $value['lat'], 'lon' => (float) $value['lon']];
        }

        if (\is_array($value) && is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null)) {
            return ['lat' => (float) $value[0], 'lon' => (float) $value[1]];
        }

        return null;
    }

    /** Date part (YYYY-MM-DD) of an ISO date or datetime ("2026-07-01T18:00:00+02:00" → "2026-07-01"). */
    private function date(mixed $value): ?string
    {
        $string = $this->firstString($value);

        return null !== $string && 1 === preg_match('/^(\d{4}-\d{2}-\d{2})/', $string, $matches) ? $matches[1] : null;
    }

    private function normalize(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');

        return strtr($lower, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
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
}
