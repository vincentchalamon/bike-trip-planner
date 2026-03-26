<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteFetcher;

use Override;
use RuntimeException;
use App\RouteFetcher\KomootHtmlExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KomootHtmlExtractorTest extends TestCase
{
    private KomootHtmlExtractor $extractor;

    #[Override]
    protected function setUp(): void
    {
        $this->extractor = new KomootHtmlExtractor();
    }

    #[Test]
    public function extractTourDataWithValidHtml(): void
    {
        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'tour' => [
                        'name' => 'Mont Ventoux Loop',
                        '_embedded' => [
                            'coordinates' => [
                                'items' => [
                                    ['lat' => 44.174, 'lng' => 5.278, 'alt' => 300.0],
                                    ['lat' => 44.175, 'lng' => 5.279, 'alt' => 350.0],
                                    ['lat' => 44.176, 'lng' => 5.280, 'alt' => 400.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->extractor->extractTourData($html);

        $this->assertSame('Mont Ventoux Loop', $result['name']);
        $this->assertCount(3, $result['coordinates']);
        $this->assertEqualsWithDelta(44.174, $result['coordinates'][0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.278, $result['coordinates'][0]->lon, 0.001);
        $this->assertEqualsWithDelta(300.0, $result['coordinates'][0]->ele, 0.01);
    }

    #[Test]
    public function extractTourDataDefaultsNameToKomootTour(): void
    {
        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'tour' => [
                        '_embedded' => [
                            'coordinates' => [
                                'items' => [
                                    ['lat' => 44.0, 'lng' => 5.0, 'alt' => 0.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->extractor->extractTourData($html);

        $this->assertSame('Komoot Tour', $result['name']);
    }

    #[Test]
    public function extractTourDataSkipsInvalidCoordinates(): void
    {
        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'tour' => [
                        'name' => 'Test Tour',
                        '_embedded' => [
                            'coordinates' => [
                                'items' => [
                                    ['lat' => 44.0, 'lng' => 5.0, 'alt' => 100.0],
                                    ['lat' => 'invalid', 'lng' => 5.0],
                                    ['lat' => 44.1, 'lng' => null],
                                    ['lat' => 44.2, 'lng' => 5.2, 'alt' => 200.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->extractor->extractTourData($html);

        $this->assertCount(2, $result['coordinates']);
    }

    #[Test]
    public function extractTourDataDefaultsElevationToZero(): void
    {
        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'tour' => [
                        'name' => 'Test',
                        '_embedded' => [
                            'coordinates' => [
                                'items' => [
                                    ['lat' => 44.0, 'lng' => 5.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->extractor->extractTourData($html);

        $this->assertEqualsWithDelta(0.0, $result['coordinates'][0]->ele, 0.01);
    }

    #[Test]
    public function extractTourDataThrowsOnMissingBootstrap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Komoot bootstrap data not found');

        $this->extractor->extractTourData('<html><body>No bootstrap here</body></html>');
    }

    #[Test]
    public function extractTourDataThrowsOnMissingPageData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Page data not found');

        $html = $this->buildBootstrapHtml(['other' => 'data']);
        $this->extractor->extractTourData($html);
    }

    #[Test]
    public function extractTourDataThrowsOnMissingTourData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tour data not found');

        $html = $this->buildBootstrapHtml(['page' => ['_embedded' => ['other' => 'data']]]);
        $this->extractor->extractTourData($html);
    }

    #[Test]
    public function extractTourDataThrowsOnEmptyCoordinates(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No coordinate items found');

        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'tour' => [
                        'name' => 'Test',
                        '_embedded' => [
                            'coordinates' => ['items' => []],
                        ],
                    ],
                ],
            ],
        ]);

        $this->extractor->extractTourData($html);
    }

    #[Test]
    public function extractTourDataThrowsWhenAllCoordinatesInvalid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid coordinates extracted');

        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'tour' => [
                        'name' => 'Test',
                        '_embedded' => [
                            'coordinates' => [
                                'items' => [
                                    ['lat' => 'bad', 'lng' => 'data'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->extractor->extractTourData($html);
    }

    #[Test]
    public function extractCollectionTourIdsWithValidHtml(): void
    {
        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'collectionHal' => [
                        'name' => 'Alps Bikepacking',
                        '_embedded' => [
                            'compilation' => [
                                '_embedded' => [
                                    'items' => [
                                        ['id' => 111],
                                        ['id' => 222],
                                        ['id' => '333'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->extractor->extractCollectionTourIds($html);

        $this->assertSame('Alps Bikepacking', $result['name']);
        $this->assertSame(['111', '222', '333'], $result['tourIds']);
    }

    #[Test]
    public function extractCollectionTourIdsDefaultsName(): void
    {
        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'collectionHal' => [
                        '_embedded' => [
                            'compilation' => [
                                '_embedded' => [
                                    'items' => [
                                        ['id' => 111],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->extractor->extractCollectionTourIds($html);

        $this->assertSame('Komoot Collection', $result['name']);
    }

    #[Test]
    public function extractCollectionTourIdsThrowsWhenNoTours(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tours found in collection data');

        $html = $this->buildBootstrapHtml([
            'page' => [
                '_embedded' => [
                    'collectionHal' => [
                        '_embedded' => [
                            'compilation' => [
                                '_embedded' => [
                                    'items' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->extractor->extractCollectionTourIds($html);
    }

    #[Test]
    public function extractBootstrapHandlesEscapedQuotes(): void
    {
        // The bootstrap content contains escaped quotes within the JSON
        $jsonData = [
            'page' => [
                '_embedded' => [
                    'tour' => [
                        'name' => 'Tour with "quotes"',
                        '_embedded' => [
                            'coordinates' => [
                                'items' => [
                                    ['lat' => 44.0, 'lng' => 5.0, 'alt' => 0.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $html = $this->buildBootstrapHtml($jsonData);
        $result = $this->extractor->extractTourData($html);

        $this->assertSame('Tour with "quotes"', $result['name']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildBootstrapHtml(array $data): string
    {
        $json = json_encode($data, \JSON_THROW_ON_ERROR);
        // Komoot wraps JSON in a JS string literal: kmtBoot.setProps("...")
        // The JSON content is string-escaped (quotes become \")
        $escaped = addcslashes($json, '"\\');

        return '<html><body><script>kmtBoot.setProps("'.$escaped.'")</script></body></html>';
    }
}
