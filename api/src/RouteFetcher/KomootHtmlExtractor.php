<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\ApiResource\Model\Coordinate;

/**
 * Komoot API is private.
 *
 * Extracts tour data from Komoot HTML pages by parsing the embedded kmtBoot.setProps() bootstrap JSON.
 *
 * @internal
 *
 * @see https://support.komoot.com/hc/en-us/articles/7464746034458-Komoot-API
 *
 * DIP: no interface — single consumer, single implementation. Extract when a second consumer arises.
 * LoD: deep chaining inherent to Komoot's nested HAL+JSON structure; validated at each level.
 */
final readonly class KomootHtmlExtractor
{
    private const string BOOTSTRAP_MARKER = 'kmtBoot.setProps("';

    /**
     * @return array{name: string, coordinates: list<Coordinate>}
     */
    public function extractTourData(string $html): array
    {
        $data = $this->extractBootstrapData($html);

        /** @var array<string, mixed>|null $page */
        $page = is_array($data['page'] ?? null) ? $data['page'] : null;
        if (null === $page) {
            throw new \RuntimeException('Page data not found in Komoot bootstrap.');
        }

        /** @var array<string, mixed>|null $embedded */
        $embedded = is_array($page['_embedded'] ?? null) ? $page['_embedded'] : null;
        if (null === $embedded) {
            throw new \RuntimeException('Embedded data not found in Komoot page.');
        }

        /** @var array<string, mixed>|null $tour */
        $tour = is_array($embedded['tour'] ?? null) ? $embedded['tour'] : null;
        if (null === $tour) {
            throw new \RuntimeException('Tour data not found in Komoot page.');
        }

        $name = \is_string($tour['name'] ?? null) ? $tour['name'] : 'Komoot Tour';

        /** @var array<string, mixed>|null $tourEmbedded */
        $tourEmbedded = is_array($tour['_embedded'] ?? null) ? $tour['_embedded'] : null;
        if (null === $tourEmbedded) {
            throw new \RuntimeException('Tour embedded data not found.');
        }

        /** @var array<string, mixed>|null $coordsContainer */
        $coordsContainer = is_array($tourEmbedded['coordinates'] ?? null) ? $tourEmbedded['coordinates'] : null;
        if (null === $coordsContainer) {
            throw new \RuntimeException('Coordinates container not found in tour data.');
        }

        /** @var list<array{lat?: mixed, lng?: mixed, alt?: mixed}> $items */
        $items = is_array($coordsContainer['items'] ?? null) ? $coordsContainer['items'] : [];

        if ([] === $items) {
            throw new \RuntimeException('No coordinate items found in tour data.');
        }

        $coordinates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lat = $item['lat'] ?? null;
            $lng = $item['lng'] ?? null;
            $alt = $item['alt'] ?? 0.0;

            if (!is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }

            $coordinates[] = new Coordinate(
                lat: (float) $lat,
                lon: (float) $lng,
                ele: is_numeric($alt) ? (float) $alt : 0.0,
            );
        }

        if ([] === $coordinates) {
            throw new \RuntimeException('No valid coordinates extracted from tour data.');
        }

        return ['name' => $name, 'coordinates' => $coordinates];
    }

    /**
     * @return array{name: string, tourIds: list<string>}
     */
    public function extractCollectionTourIds(string $html): array
    {
        $data = $this->extractBootstrapData($html);

        /** @var array<string, mixed>|null $page */
        $page = is_array($data['page'] ?? null) ? $data['page'] : null;
        if (null === $page) {
            throw new \RuntimeException('Page data not found in Komoot bootstrap.');
        }

        /** @var array<string, mixed>|null $embedded */
        $embedded = is_array($page['_embedded'] ?? null) ? $page['_embedded'] : null;
        if (null === $embedded) {
            throw new \RuntimeException('Embedded data not found in Komoot page.');
        }

        /** @var array<string, mixed>|null $collectionHal */
        $collectionHal = is_array($embedded['collectionHal'] ?? null) ? $embedded['collectionHal'] : null;
        if (null === $collectionHal) {
            throw new \RuntimeException('Collection data not found in Komoot page.');
        }

        $name = \is_string($collectionHal['name'] ?? null) ? $collectionHal['name'] : 'Komoot Collection';

        /** @var array<string, mixed>|null $halEmbedded */
        $halEmbedded = is_array($collectionHal['_embedded'] ?? null) ? $collectionHal['_embedded'] : null;

        /** @var array<string, mixed>|null $compilation */
        $compilation = is_array($halEmbedded['compilation'] ?? null) ? $halEmbedded['compilation'] : null;

        /** @var array<string, mixed>|null $compilationEmbedded */
        $compilationEmbedded = is_array($compilation['_embedded'] ?? null) ? $compilation['_embedded'] : null;

        /** @var list<array{id?: mixed}> $items */
        $items = is_array($compilationEmbedded['items'] ?? null) ? $compilationEmbedded['items'] : [];

        $tourIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                $tourIds[] = (string) $id;
            }
        }

        if ([] === $tourIds) {
            throw new \RuntimeException('No tours found in collection data.');
        }

        return ['name' => $name, 'tourIds' => $tourIds];
    }

    /**
     * Extracts the bootstrap JSON by scanning for the unescaped closing quote.
     * A regex-based approach fails here because the JSON payload is ~500KB,
     * which exceeds PHP's default PCRE backtracking limit.
     *
     * @return array<string, mixed>
     */
    private function extractBootstrapData(string $html): array
    {
        $markerPos = strpos($html, self::BOOTSTRAP_MARKER);
        if (false === $markerPos) {
            throw new \RuntimeException('Komoot bootstrap data not found in page HTML.');
        }

        $contentStart = $markerPos + \strlen(self::BOOTSTRAP_MARKER);
        $len = \strlen($html);

        // Scan for the unescaped closing " that ends the JS string literal.
        $i = $contentStart;
        while ($i < $len) {
            if ('\\' === $html[$i]) {
                $i += 2; // skip escaped character
                continue;
            }

            if ('"' === $html[$i]) {
                break;
            }

            ++$i;
        }

        if ($i >= $len) {
            throw new \RuntimeException('Unterminated Komoot bootstrap string.');
        }

        $rawContent = substr($html, $contentStart, $i - $contentStart);

        // The content is a JSON object with JS string escaping (\").
        // Wrap in quotes and json_decode to unescape, then parse as JSON.
        $jsonString = json_decode('"'.$rawContent.'"');
        if (!\is_string($jsonString)) {
            throw new \RuntimeException('Failed to decode Komoot bootstrap string.');
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($jsonString, true);
        if (!\is_array($data)) {
            throw new \RuntimeException('Failed to parse Komoot bootstrap JSON.');
        }

        return $data;
    }
}
