<?php

declare(strict_types=1);

namespace App\Tests\Unit\Accommodation;

use App\Accommodation\AccommodationMetadataExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccommodationMetadataExtractorTest extends TestCase
{
    private AccommodationMetadataExtractor $extractor;

    #[\Override]
    protected function setUp(): void
    {
        $this->extractor = new AccommodationMetadataExtractor();
    }

    #[Test]
    public function extractFromJsonLd(): void
    {
        $html = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {
                    "@type": "Hotel",
                    "name": "Grand Hotel",
                    "priceRange": "80 - 150€"
                }
                </script>
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame('Grand Hotel', $result->name);
        $this->assertSame('hotel', $result->type);
        $this->assertSame(80.0, $result->priceMin);
        $this->assertSame(150.0, $result->priceMax);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeMapProvider(): iterable
    {
        yield 'Hotel' => ['Hotel', 'hotel'];
        yield 'Motel' => ['Motel', 'motel'];
        yield 'Hostel' => ['Hostel', 'hostel'];
        yield 'Campground' => ['Campground', 'camp_site'];
        yield 'LodgingBusiness' => ['LodgingBusiness', 'guest_house'];
        yield 'BedAndBreakfast' => ['BedAndBreakfast', 'guest_house'];
        yield 'Resort' => ['Resort', 'hotel'];
    }

    #[DataProvider('typeMapProvider')]
    #[Test]
    public function extractMapsJsonLdTypes(string $jsonLdType, string $expectedType): void
    {
        $html = sprintf(
            '<html><head><script type="application/ld+json">{"@type": "%s", "name": "Test"}</script></head><body></body></html>',
            $jsonLdType,
        );

        $result = $this->extractor->extract($html);

        $this->assertSame($expectedType, $result->type);
    }

    #[Test]
    public function extractFromOpenGraph(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:title" content="Mountain Lodge">
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame('Mountain Lodge', $result->name);
    }

    #[Test]
    public function extractFromTitleTag(): void
    {
        $html = '<html><head><title>Simple Camping</title></head><body></body></html>';

        $result = $this->extractor->extract($html);

        $this->assertSame('Simple Camping', $result->name);
    }

    #[Test]
    public function extractPrioritizesJsonLdOverOpenGraph(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:title" content="OG Name">
                <script type="application/ld+json">{"@type": "Hotel", "name": "JSON-LD Name"}</script>
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame('JSON-LD Name', $result->name);
    }

    #[Test]
    public function extractPrioritizesOpenGraphOverTitle(): void
    {
        $html = <<<'HTML'
            <html><head>
                <title>Title Name</title>
                <meta property="og:title" content="OG Name">
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame('OG Name', $result->name);
    }

    #[Test]
    public function extractReturnsNullsForEmptyHtml(): void
    {
        $result = $this->extractor->extract('<html><head></head><body></body></html>');

        $this->assertNull($result->name);
        $this->assertNull($result->type);
        $this->assertNull($result->priceMin);
        $this->assertNull($result->priceMax);
    }

    #[Test]
    public function extractJsonLdWithGraph(): void
    {
        $html = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {
                    "@graph": [
                        {"@type": "Organization"},
                        {"@type": "Hostel", "name": "Mountain Hostel", "priceRange": "25€"}
                    ]
                }
                </script>
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame('Mountain Hostel', $result->name);
        $this->assertSame('hostel', $result->type);
        $this->assertSame(25.0, $result->priceMin);
        $this->assertSame(25.0, $result->priceMax);
    }

    #[Test]
    public function extractJsonLdWithArrayType(): void
    {
        $html = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {
                    "@type": ["Hotel", "LocalBusiness"],
                    "name": "Multi-Type Hotel"
                }
                </script>
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame('hotel', $result->type);
        $this->assertSame('Multi-Type Hotel', $result->name);
    }

    #[Test]
    public function parsePriceRangeWithDash(): void
    {
        $html = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {"@type": "Hotel", "name": "Test", "priceRange": "50 - 100€"}
                </script>
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame(50.0, $result->priceMin);
        $this->assertSame(100.0, $result->priceMax);
    }

    #[Test]
    public function parsePriceRangeWithCommaDecimals(): void
    {
        $html = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {"@type": "Hotel", "name": "Test", "priceRange": "10,50 - 20,00€"}
                </script>
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame(10.5, $result->priceMin);
        $this->assertSame(20.0, $result->priceMax);
    }

    #[Test]
    public function parseSinglePrice(): void
    {
        $html = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {"@type": "Hotel", "name": "Test", "priceRange": "75€"}
                </script>
            </head><body></body></html>
            HTML;

        $result = $this->extractor->extract($html);

        $this->assertSame(75.0, $result->priceMin);
        $this->assertSame(75.0, $result->priceMax);
    }

    #[Test]
    public function extractPricesFromHtmlFindsEuroPrices(): void
    {
        $html = '<html><body><p>Chambre double: 65€</p><p>Suite: 120€</p></body></html>';

        $result = $this->extractor->extractPricesFromHtml($html);

        $this->assertNotNull($result);
        $this->assertSame(65.0, $result['priceMin']);
        $this->assertSame(120.0, $result['priceMax']);
    }

    #[Test]
    public function extractPricesFromHtmlFiltersUnrealisticPrices(): void
    {
        $html = '<html><body><p>1€ discount</p><p>Room: 80€</p><p>Total: 999€</p></body></html>';

        $result = $this->extractor->extractPricesFromHtml($html);

        $this->assertNotNull($result);
        // Only 80€ is in the 5-500 range
        $this->assertSame(80.0, $result['priceMin']);
        $this->assertSame(80.0, $result['priceMax']);
    }

    #[Test]
    public function extractPricesFromHtmlReturnsNullWithoutPrices(): void
    {
        $html = '<html><body><p>No prices here</p></body></html>';

        $this->assertNull($this->extractor->extractPricesFromHtml($html));
    }

    #[Test]
    public function discoverPricePagePathsFindsLinks(): void
    {
        $html = <<<'HTML'
            <html><body>
                <a href="/tarifs">Nos tarifs</a>
                <a href="/contact">Contact</a>
                <a href="/chambres">Chambres et prix</a>
            </body></html>
            HTML;

        $result = $this->extractor->discoverPricePagePaths($html, 'https://example.com');

        $this->assertContains('https://example.com/tarifs', $result);
        $this->assertContains('https://example.com/chambres', $result);
        $this->assertNotContains('https://example.com/contact', $result);
    }

    #[Test]
    public function discoverPricePagePathsResolvesRelativeUrls(): void
    {
        $html = '<html><body><a href="/prices">Prices</a></body></html>';

        $result = $this->extractor->discoverPricePagePaths($html, 'https://hotel.example.com/home');

        $this->assertContains('https://hotel.example.com/prices', $result);
    }

    #[Test]
    public function discoverPricePagePathsRejectsDifferentHost(): void
    {
        $html = '<html><body><a href="https://other.com/prices">Prices</a></body></html>';

        $result = $this->extractor->discoverPricePagePaths($html, 'https://hotel.example.com');

        $this->assertSame([], $result);
    }

    #[Test]
    public function discoverPricePagePathsLimitsToThree(): void
    {
        $html = <<<'HTML'
            <html><body>
                <a href="/tarifs">Tarifs</a>
                <a href="/prix">Prix</a>
                <a href="/prices">Prices</a>
                <a href="/rates">Rates</a>
            </body></html>
            HTML;

        $result = $this->extractor->discoverPricePagePaths($html, 'https://example.com');

        $this->assertLessThanOrEqual(3, \count($result));
    }

    #[Test]
    public function discoverPricePagePathsMatchesByLinkText(): void
    {
        $html = '<html><body><a href="/page">Voir les tarifs</a></body></html>';

        $result = $this->extractor->discoverPricePagePaths($html, 'https://example.com');

        $this->assertContains('https://example.com/page', $result);
    }

    #[Test]
    public function discoverPricePagePathsReturnsEmptyForNoLinks(): void
    {
        $html = '<html><body><a href="/about">About us</a></body></html>';

        $result = $this->extractor->discoverPricePagePaths($html, 'https://example.com');

        $this->assertSame([], $result);
    }
}
