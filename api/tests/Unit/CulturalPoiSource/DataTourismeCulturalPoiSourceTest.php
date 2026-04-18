<?php

declare(strict_types=1);

namespace App\Tests\Unit\CulturalPoiSource;

use App\CulturalPoiSource\DataTourismeCulturalPoiSource;
use App\DataTourisme\DataTourismeClientInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataTourismeCulturalPoiSourceTest extends TestCase
{
    private function makeSource(DataTourismeClientInterface $client): DataTourismeCulturalPoiSource
    {
        return new DataTourismeCulturalPoiSource($client);
    }

    /**
     * @return list<list<array{lat: float, lon: float}>>
     */
    private function stageGeometries(): array
    {
        return [
            [['lat' => 48.0, 'lon' => 2.0], ['lat' => 48.5, 'lon' => 2.5]],
        ];
    }

    #[Test]
    public function getNameReturnsDatatourisme(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);

        $source = $this->makeSource($client);

        self::assertSame('datatourisme', $source->getName());
    }

    #[Test]
    public function isEnabledDelegatesToClient(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(false);

        $source = $this->makeSource($client);

        self::assertFalse($source->isEnabled());
    }

    #[Test]
    public function fetchForStagesReturnsEmptyWhenClientIsDisabled(): void
    {
        $client = $this->createMock(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(false);
        $client->expects($this->never())->method('request');

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertSame([], $result);
    }

    #[Test]
    public function museumItemIsMapped(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Museum'],
                    'rdfs:label' => [['@value' => 'Musée du Louvre', '@language' => 'fr']],
                    'hasGeometry' => ['schema:latitude' => 48.8606, 'schema:longitude' => 2.3376],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('Musée du Louvre', $result[0]['name']);
        self::assertSame('museum', $result[0]['type']);
        self::assertSame('datatourisme', $result[0]['source']);
        self::assertSame(48.8606, $result[0]['lat']);
        self::assertSame(2.3376, $result[0]['lon']);
    }

    #[Test]
    public function openingHoursAreMapped(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Museum'],
                    'rdfs:label' => 'Château de Versailles',
                    'hasGeometry' => ['schema:latitude' => 48.8, 'schema:longitude' => 2.1],
                    'openingHoursSpecification' => [
                        [
                            'schema:dayOfWeek' => ['schema:Tuesday', 'schema:Wednesday'],
                            'schema:opens' => '09:00',
                            'schema:closes' => '18:00',
                        ],
                    ],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertNotNull($result[0]['openingHours']);
        self::assertStringContainsString('09:00', $result[0]['openingHours']);
        self::assertStringContainsString('18:00', $result[0]['openingHours']);
    }

    #[Test]
    public function estimatedPriceIsExtracted(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Museum'],
                    'rdfs:label' => 'Musée Picasso',
                    'hasGeometry' => ['schema:latitude' => 48.8, 'schema:longitude' => 2.1],
                    'offers' => [
                        [
                            'priceSpecification' => [
                                ['schema:price' => 12.5, 'schema:priceCurrency' => 'EUR'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame(12.5, $result[0]['estimatedPrice']);
    }

    #[Test]
    public function descriptionFromRdfsCommentIsExtracted(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Landmark'],
                    'rdfs:label' => 'Tour Eiffel',
                    'hasGeometry' => ['schema:latitude' => 48.858, 'schema:longitude' => 2.294],
                    'rdfs:comment' => [['@value' => 'Iconic iron tower', '@language' => 'en']],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('Iconic iron tower', $result[0]['description']);
    }

    #[Test]
    public function wikidataIdIsExtractedFromOwlSameAs(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Museum'],
                    'rdfs:label' => 'Orsay',
                    'hasGeometry' => ['schema:latitude' => 48.8, 'schema:longitude' => 2.3],
                    'owl:sameAs' => ['https://www.wikidata.org/entity/Q23402', 'https://dbpedia.org/resource/Mus%C3%A9e_d%27Orsay'],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('Q23402', $result[0]['wikidataId']);
    }

    #[Test]
    public function itemWithoutNameIsSkipped(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Museum'],
                    'hasGeometry' => ['schema:latitude' => 48.8, 'schema:longitude' => 2.3],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(0, $result);
    }

    #[Test]
    public function itemWithoutCoordinatesIsSkipped(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Museum'],
                    'rdfs:label' => 'No Coords Museum',
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(0, $result);
    }

    #[Test]
    public function emptyResultsReturnEmptyArray(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn(['results' => []]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertSame([], $result);
    }

    #[Test]
    public function memberKeyIsAcceptedAsAlternativeToResults(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'member' => [
                [
                    '@type' => ['schema:Museum'],
                    'rdfs:label' => 'Cluny',
                    'hasGeometry' => ['schema:latitude' => 48.85, 'schema:longitude' => 2.34],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('Cluny', $result[0]['name']);
    }

    #[Test]
    public function naturalHeritageIsMappedAsViewpoint(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['urn:resource:NaturalHeritage'],
                    'rdfs:label' => 'Gorges du Verdon',
                    'hasGeometry' => ['schema:latitude' => 43.7, 'schema:longitude' => 6.3],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('viewpoint', $result[0]['type']);
    }

    #[Test]
    public function culturalSiteIsMappedAsMonument(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['urn:resource:CulturalSite'],
                    'rdfs:label' => 'Abbaye de Fontenay',
                    'hasGeometry' => ['schema:latitude' => 47.6, 'schema:longitude' => 4.4],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('monument', $result[0]['type']);
    }

    #[Test]
    public function nonEuroPriceIsIgnored(): void
    {
        $client = $this->createStub(DataTourismeClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('request')->willReturn([
            'results' => [
                [
                    '@type' => ['schema:Museum'],
                    'rdfs:label' => 'British Museum',
                    'hasGeometry' => ['schema:latitude' => 51.5, 'schema:longitude' => -0.1],
                    'offers' => [
                        [
                            'priceSpecification' => [
                                ['schema:price' => 20.0, 'schema:priceCurrency' => 'GBP'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $source = $this->makeSource($client);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertNull($result[0]['estimatedPrice']);
    }
}
