<?php

declare(strict_types=1);

namespace App\Tests\Unit\CulturalPoiSource;

use App\CulturalPoiSource\CulturalPoiSourceInterface;
use App\CulturalPoiSource\CulturalPoiSourceRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CulturalPoiSourceRegistryTest extends TestCase
{
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
}
