<?php

declare(strict_types=1);

namespace App\Tests\Unit\CulturalPoiSource;

use App\CulturalPoiSource\CulturalPoiSourceInterface;
use App\CulturalPoiSource\CulturalPoiSourceRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CulturalPoiSourceRegistryTest extends TestCase
{
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
    public function fetchAllForStagesMergesResultsFromEnabledSources(): void
    {
        $sourceA = $this->createStub(CulturalPoiSourceInterface::class);
        $sourceA->method('isEnabled')->willReturn(true);
        $sourceA->method('fetchForStages')->willReturn([
            ['name' => 'Museum A', 'type' => 'museum', 'lat' => 48.1, 'lon' => 2.1, 'source' => 'osm'],
        ]);

        $sourceB = $this->createStub(CulturalPoiSourceInterface::class);
        $sourceB->method('isEnabled')->willReturn(true);
        $sourceB->method('fetchForStages')->willReturn([
            ['name' => 'Museum B', 'type' => 'museum', 'lat' => 48.2, 'lon' => 2.2, 'source' => 'datatourisme'],
        ]);

        $registry = new CulturalPoiSourceRegistry([$sourceA, $sourceB]);
        $result = $registry->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(2, $result);
        self::assertSame('Museum A', $result[0]['name']);
        self::assertSame('Museum B', $result[1]['name']);
    }

    #[Test]
    public function disabledSourceIsSkipped(): void
    {
        $enabled = $this->createStub(CulturalPoiSourceInterface::class);
        $enabled->method('isEnabled')->willReturn(true);
        $enabled->method('fetchForStages')->willReturn([
            ['name' => 'Active POI', 'type' => 'museum', 'lat' => 48.1, 'lon' => 2.1, 'source' => 'osm'],
        ]);

        $disabled = $this->createMock(CulturalPoiSourceInterface::class);
        $disabled->method('isEnabled')->willReturn(false);
        $disabled->expects($this->never())->method('fetchForStages');

        $registry = new CulturalPoiSourceRegistry([$enabled, $disabled]);
        $result = $registry->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
    }

    #[Test]
    public function emptySourcesReturnsEmptyArray(): void
    {
        $registry = new CulturalPoiSourceRegistry([]);
        $result = $registry->fetchAllForStages($this->stageGeometries(), 500);

        self::assertSame([], $result);
    }

    #[Test]
    public function radiusIsForwardedToSources(): void
    {
        $source = $this->createMock(CulturalPoiSourceInterface::class);
        $source->method('isEnabled')->willReturn(true);
        $source->expects($this->once())
            ->method('fetchForStages')
            ->with($this->stageGeometries(), 1000)
            ->willReturn([]);

        $registry = new CulturalPoiSourceRegistry([$source]);
        $registry->fetchAllForStages($this->stageGeometries(), 1000);
    }

    #[Test]
    public function datatourismeOverridesOsmWhenSameWikidataId(): void
    {
        $osm = $this->createStub(CulturalPoiSourceInterface::class);
        $osm->method('isEnabled')->willReturn(true);
        $osm->method('fetchForStages')->willReturn([
            ['name' => 'Louvre (OSM)', 'type' => 'museum', 'lat' => 48.86, 'lon' => 2.33,
                'source' => 'osm', 'wikidataId' => 'Q19675',
                'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
        ]);

        $dt = $this->createStub(CulturalPoiSourceInterface::class);
        $dt->method('isEnabled')->willReturn(true);
        $dt->method('fetchForStages')->willReturn([
            ['name' => 'Louvre (DT)', 'type' => 'museum', 'lat' => 48.86, 'lon' => 2.33,
                'source' => 'datatourisme', 'wikidataId' => 'Q19675',
                'openingHours' => 'Mon–Sun 09:00–18:00', 'estimatedPrice' => 17.0, 'description' => null],
        ]);

        $registry = new CulturalPoiSourceRegistry([$osm, $dt]);
        $result = $registry->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('datatourisme', $result[0]['source']);
        self::assertSame('Louvre (DT)', $result[0]['name']);
    }

    #[Test]
    public function poisWithoutWikidataIdAreAllKept(): void
    {
        $source = $this->createStub(CulturalPoiSourceInterface::class);
        $source->method('isEnabled')->willReturn(true);
        $source->method('fetchForStages')->willReturn([
            ['name' => 'POI A', 'type' => 'museum', 'lat' => 48.1, 'lon' => 2.1, 'source' => 'osm', 'wikidataId' => null,
                'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
            ['name' => 'POI B', 'type' => 'monument', 'lat' => 48.2, 'lon' => 2.2, 'source' => 'osm', 'wikidataId' => null,
                'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
        ]);

        $registry = new CulturalPoiSourceRegistry([$source]);
        $result = $registry->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(2, $result);
    }
}
