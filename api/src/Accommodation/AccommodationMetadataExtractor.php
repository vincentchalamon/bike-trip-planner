<?php

declare(strict_types=1);

namespace App\Accommodation;

use DOMDocument;
use DOMXPath;
use DOMElement;

// DIP: no interface — single consumer, single implementation. Extract when a second consumer arises.
final class AccommodationMetadataExtractor
{
    private const array TYPE_MAP = [
        'Hotel' => 'hotel',
        'Motel' => 'motel',
        'Hostel' => 'hostel',
        'Campground' => 'camp_site',
        'LodgingBusiness' => 'guest_house',
        'BedAndBreakfast' => 'guest_house',
        'Resort' => 'hotel',
    ];

    public function extract(string $html): AccommodationScrapedData
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($html, \LIBXML_NONET | \LIBXML_NOENT);
        $xpath = new DOMXPath($doc);

        $jsonLd = $this->extractJsonLd($xpath);
        $og = $this->extractOpenGraph($xpath);
        $titleTag = $this->extractTitle($xpath);

        $name = $jsonLd['name'] ?? $og['name'] ?? $titleTag;
        $type = $jsonLd['type'] ?? null;
        $priceMin = $jsonLd['priceMin'] ?? null;
        $priceMax = $jsonLd['priceMax'] ?? null;

        return new AccommodationScrapedData(
            name: $name,
            type: $type,
            priceMin: $priceMin,
            priceMax: $priceMax,
        );
    }

    /**
     * @return array{name?: string, type?: string, priceMin?: float, priceMax?: float}
     */
    private function extractJsonLd(DOMXPath $xpath): array
    {
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if (false === $scripts) {
            return [];
        }

        $result = [];

        foreach ($scripts as $script) {
            if (!$script instanceof DOMElement) {
                continue;
            }

            $content = $script->textContent;
            $data = json_decode($content, true);
            if (!\is_array($data)) {
                continue;
            }

            $items = $this->findLodgingItems($data);
            foreach ($items as $item) {
                if (!isset($result['name']) && isset($item['name']) && \is_string($item['name'])) {
                    $result['name'] = $item['name'];
                }

                if (!isset($result['type']) && isset($item['@type'])) {
                    $type = \is_array($item['@type']) ? ($item['@type'][0] ?? null) : $item['@type'];
                    if (\is_string($type) && isset(self::TYPE_MAP[$type])) {
                        $result['type'] = self::TYPE_MAP[$type];
                    }
                }

                if (!isset($result['priceMin']) && isset($item['priceRange']) && \is_string($item['priceRange'])) {
                    $prices = $this->parsePriceRange($item['priceRange']);
                    if (null !== $prices) {
                        $result['priceMin'] = $prices[0];
                        $result['priceMax'] = $prices[1];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function findLodgingItems(array $data): array
    {
        $lodgingTypes = array_keys(self::TYPE_MAP);
        $items = [];

        if (isset($data['@graph']) && \is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (\is_array($node)) {
                    $items = array_merge($items, $this->findLodgingItems($node));
                }
            }

            return $items;
        }

        if (isset($data['@type'])) {
            $types = \is_array($data['@type']) ? $data['@type'] : [$data['@type']];
            foreach ($types as $type) {
                if (\is_string($type) && \in_array($type, $lodgingTypes, true)) {
                    $items[] = $data;
                    break;
                }
            }
        }

        // Also accept any item with a name — useful as fallback
        if ([] === $items && isset($data['name']) && \is_string($data['name'])) {
            $items[] = $data;
        }

        return $items;
    }

    /**
     * @return array{float, float}|null
     */
    private function parsePriceRange(string $priceRange): ?array
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)/', $priceRange, $matches)) {
            $min = (float) str_replace(',', '.', $matches[1]);
            $max = (float) str_replace(',', '.', $matches[2]);

            return [$min, $max];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)/', $priceRange, $matches)) {
            $price = (float) str_replace(',', '.', $matches[1]);

            return [$price, $price];
        }

        return null;
    }

    /**
     * @return array{name?: string}
     */
    private function extractOpenGraph(DOMXPath $xpath): array
    {
        $result = [];

        $titleNode = $xpath->query('//meta[@property="og:title"]/@content');
        if (false !== $titleNode && $titleNode->length > 0) {
            $value = $titleNode->item(0)?->nodeValue;
            if (\is_string($value) && '' !== $value) {
                $result['name'] = $value;
            }
        }

        return $result;
    }

    private function extractTitle(DOMXPath $xpath): ?string
    {
        $titleNode = $xpath->query('//title');
        if (false !== $titleNode && $titleNode->length > 0) {
            $item = $titleNode->item(0);
            $value = $item instanceof DOMElement ? $item->textContent : null;
            if (\is_string($value) && '' !== $value) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Extracts prices from an HTML page by scanning for price patterns.
     * Used for secondary price pages (/tarifs, /prices, etc.).
     *
     * @return array{priceMin: float, priceMax: float}|null
     */
    public function extractPricesFromHtml(string $html): ?array
    {
        // Strip HTML tags but keep text content
        $text = strip_tags($html);
        // Find all numeric values near currency symbols (€, EUR)
        if (preg_match_all('/(\d+(?:[.,]\d{1,2})?)\s*[€]|[€]\s*(\d+(?:[.,]\d{1,2})?)/', $text, $matches)) {
            $prices = [];
            foreach ($matches[1] as $m) {
                if ('' !== $m) {
                    $prices[] = (float) str_replace(',', '.', $m);
                }
            }

            foreach ($matches[2] as $m) {
                if ('' !== $m) {
                    $prices[] = (float) str_replace(',', '.', $m);
                }
            }

            // Filter unrealistic prices
            $prices = array_filter($prices, static fn (float $p): bool => $p >= 5.0 && $p <= 500.0);
            $prices = array_values($prices);

            if (\count($prices) >= 1) {
                return ['priceMin' => min($prices), 'priceMax' => max($prices)];
            }
        }

        return null;
    }

    /**
     * Discovers potential pricing page URLs from the homepage HTML.
     *
     * @return list<string>
     */
    public function discoverPricePagePaths(string $html, string $baseUrl): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($html, \LIBXML_NONET | \LIBXML_NOENT);
        $xpath = new DOMXPath($doc);

        $priceKeywords = ['tarif', 'prix', 'price', 'rate', 'booking', 'reservation', 'chambre', 'room', 'hébergement'];
        $links = $xpath->query('//a[@href]');
        if (false === $links) {
            return [];
        }

        $found = [];
        $parsedBase = parse_url($baseUrl);
        $baseHost = $parsedBase['host'] ?? '';

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = $link->getAttribute('href');
            $text = strtolower(trim($link->textContent));
            $hrefLower = strtolower($href);

            foreach ($priceKeywords as $keyword) {
                if (str_contains($hrefLower, $keyword) || str_contains($text, $keyword)) {
                    // Resolve relative URLs
                    if (str_starts_with($href, '/')) {
                        $scheme = $parsedBase['scheme'] ?? 'https';
                        $href = \sprintf('%s://%s%s', $scheme, $baseHost, $href);
                    }

                    // Only follow URLs on the same host
                    $parsedHref = parse_url($href);
                    if (isset($parsedHref['host']) && $parsedHref['host'] === $baseHost) {
                        $found[] = $href;
                    }

                    break;
                }
            }

            if (\count($found) >= 3) {
                break;
            }
        }

        return array_values(array_unique($found));
    }
}
