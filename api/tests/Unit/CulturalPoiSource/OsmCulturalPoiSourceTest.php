<?php

declare(strict_types=1);

namespace App\Tests\Unit\CulturalPoiSource;

use App\CulturalPoiSource\OsmCulturalPoiSource;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OsmCulturalPoiSourceTest extends TestCase
{
    private function makeSource(
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
    ): OsmCulturalPoiSource {
        return new OsmCulturalPoiSource($scanner, $queryBuilder);
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
    public function isEnabledAlwaysReturnsTrue(): void
    {
        $source = $this->makeSource(
            $this->createStub(ScannerInterface::class),
            $this->createStub(QueryBuilderInterface::class),
        );

        self::assertTrue($source->isEnabled());
    }

    #[Test]
    public function getNameReturnsOsm(): void
    {
        $source = $this->makeSource(
            $this->createStub(ScannerInterface::class),
            $this->createStub(QueryBuilderInterface::class),
        );

        self::assertSame('osm', $source->getName());
    }

    #[Test]
    public function museumIsMapped(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'museum', 'name' => 'Louvre']],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('Louvre', $result[0]['name']);
        self::assertSame('museum', $result[0]['type']);
        self::assertSame('osm', $result[0]['source']);
        self::assertNull($result[0]['openingHours']);
        self::assertNull($result[0]['estimatedPrice']);
        self::assertNull($result[0]['description']);
    }

    #[Test]
    public function wikidataTagIsExtracted(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'museum', 'name' => 'Louvre', 'wikidata' => 'Q19675']],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('Q19675', $result[0]['wikidataId']);
    }

    #[Test]
    public function elementWithMissingCoordinatesIsSkipped(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['tags' => ['tourism' => 'museum', 'name' => 'No Coords Museum']],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(0, $result);
    }

    #[Test]
    public function nonNotableHistoricTagIsSkipped(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['historic' => 'milestone', 'name' => 'Old Stone']],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(0, $result);
    }

    #[Test]
    public function notableHistoricCastleIsMapped(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['historic' => 'castle', 'name' => 'Château Frontenac']],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('castle', $result[0]['type']);
    }

    #[Test]
    public function unknownTourismTagIsSkipped(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'hotel', 'name' => 'Grand Hotel']],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(0, $result);
    }

    #[Test]
    public function centerCoordinatesAreUsedForWayElements(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'center' => ['lat' => 48.3, 'lon' => 2.3],
                    'tags' => ['tourism' => 'attraction', 'name' => 'Big Park'],
                ],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame(48.3, $result[0]['lat']);
        self::assertSame(2.3, $result[0]['lon']);
    }

    #[Test]
    public function emptyWikidataTagResultsInNullWikidataId(): void
    {
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'museum', 'name' => 'Museum', 'wikidata' => '']],
            ],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $source = $this->makeSource($scanner, $queryBuilder);
        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertNull($result[0]['wikidataId']);
    }
}
