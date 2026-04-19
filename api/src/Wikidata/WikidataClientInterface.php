<?php

declare(strict_types=1);

namespace App\Wikidata;

interface WikidataClientInterface
{
    /**
     * Executes a SPARQL query against the Wikidata Query Service.
     * Returns the decoded JSON bindings array, or an empty array on error.
     *
     * @return list<array<string, array{type: string, value: string}>>
     */
    public function query(string $sparql): array;
}
