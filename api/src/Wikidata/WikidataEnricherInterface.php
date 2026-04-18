<?php

declare(strict_types=1);

namespace App\Wikidata;

interface WikidataEnricherInterface
{
    /**
     * Enriches a batch of Wikidata entities with label, description, image, website,
     * opening hours, and Wikipedia link. One SPARQL query per 50 Q-IDs.
     *
     * Returns an associative array keyed by Q-ID (e.g. "Q12345").
     * Fields present only when available: label, description, imageUrl, website,
     * openingHours, wikipediaUrl.
     *
     * Errors (timeout, 5xx) are handled silently — returns empty array.
     *
     * @param list<string> $qIds   Wikidata entity IDs (e.g. ["Q12345", "Q67890"])
     * @param string       $locale BCP-47 language tag used as primary language for labels
     *
     * @return array<string, array{label?: string, description?: string, imageUrl?: string, website?: string, openingHours?: string, wikipediaUrl?: string}>
     */
    public function enrichBatch(array $qIds, string $locale): array;
}
