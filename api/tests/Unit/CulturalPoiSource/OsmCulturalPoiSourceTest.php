<?php

declare(strict_types=1);

namespace App\Tests\Unit\CulturalPoiSource;

use App\CulturalPoiSource\OsmCulturalPoiSource;
use App\Osm\CulturalPoiRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OsmCulturalPoiSourceTest extends TestCase
{
    #[Test]
    public function isEnabledAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->makeSource()->isEnabled());
    }

    #[Test]
    public function getNameReturnsOsm(): void
    {
        self::assertSame('osm', $this->makeSource()->getName());
    }

    #[Test]
    public function mapsRepositoryRowToCandidate(): void
    {
        $source = $this->makeSource($this->repository([
            ['name' => 'Louvre', 'category' => 'museum', 'lat' => 48.2, 'lon' => 2.2, 'wikidata' => null],
        ]));

        $result = $source->fetchForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('Louvre', $result[0]['name']);
        self::assertSame('museum', $result[0]['type']);
        self::assertSame(48.2, $result[0]['lat']);
        self::assertSame(2.2, $result[0]['lon']);
        self::assertSame('osm', $result[0]['source']);
        self::assertNull($result[0]['openingHours']);
        self::assertNull($result[0]['estimatedPrice']);
        self::assertNull($result[0]['description']);
    }

    #[Test]
    public function historicTypePassesThrough(): void
    {
        $source = $this->makeSource($this->repository([
            ['name' => 'Château', 'category' => 'castle', 'lat' => 48.2, 'lon' => 2.2, 'wikidata' => null],
        ]));

        self::assertSame('castle', $source->fetchForStages($this->stageGeometries(), 500)[0]['type']);
    }

    #[Test]
    public function extractsWikidataId(): void
    {
        $source = $this->makeSource($this->repository([
            ['name' => 'Louvre', 'category' => 'museum', 'lat' => 48.2, 'lon' => 2.2, 'wikidata' => 'Q19675'],
        ]));

        self::assertSame('Q19675', $source->fetchForStages($this->stageGeometries(), 500)[0]['wikidataId']);
    }

    #[Test]
    public function nullWikidataMapsToNull(): void
    {
        $source = $this->makeSource($this->repository([
            ['name' => 'Museum', 'category' => 'museum', 'lat' => 48.2, 'lon' => 2.2, 'wikidata' => null],
        ]));

        self::assertNull($source->fetchForStages($this->stageGeometries(), 500)[0]['wikidataId']);
    }

    #[Test]
    public function nameFallsBackToTypeWhenNull(): void
    {
        $source = $this->makeSource($this->repository([
            ['name' => null, 'category' => 'viewpoint', 'lat' => 48.2, 'lon' => 2.2, 'wikidata' => null],
        ]));

        self::assertSame('viewpoint', $source->fetchForStages($this->stageGeometries(), 500)[0]['name']);
    }

    #[Test]
    public function flattensStageGeometriesIntoTheCorridorRoute(): void
    {
        $repository = $this->createStub(CulturalPoiRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturnCallback(
            static function (array $route, int $radiusMeters): array {
                self::assertSame([
                    ['lat' => 48.0, 'lon' => 2.0],
                    ['lat' => 48.5, 'lon' => 2.5],
                    ['lat' => 49.0, 'lon' => 3.0],
                ], $route);
                self::assertSame(500, $radiusMeters);

                return [];
            },
        );

        new OsmCulturalPoiSource($repository)->fetchForStages([
            [['lat' => 48.0, 'lon' => 2.0], ['lat' => 48.5, 'lon' => 2.5]],
            [['lat' => 49.0, 'lon' => 3.0]],
        ], 500);
    }

    /**
     * @return list<list<array{lat: float, lon: float}>>
     */
    private function stageGeometries(): array
    {
        return [[['lat' => 48.0, 'lon' => 2.0], ['lat' => 48.5, 'lon' => 2.5]]];
    }

    /**
     * @param list<array{name: ?string, category: string, lat: float, lon: float, wikidata: ?string}> $rows
     */
    private function repository(array $rows): CulturalPoiRepositoryInterface
    {
        // Default the provisioner-enriched columns the read layer now returns.
        $rows = array_map(
            static fn (array $row): array => $row + ['openingHours' => null, 'description' => null, 'imageUrl' => null, 'wikipediaUrl' => null],
            $rows,
        );

        $repository = $this->createStub(CulturalPoiRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn($rows);

        return $repository;
    }

    private function makeSource(?CulturalPoiRepositoryInterface $repository = null): OsmCulturalPoiSource
    {
        return new OsmCulturalPoiSource($repository ?? $this->createStub(CulturalPoiRepositoryInterface::class));
    }
}
