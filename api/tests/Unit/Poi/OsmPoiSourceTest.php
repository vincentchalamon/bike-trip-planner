<?php

declare(strict_types=1);

namespace App\Tests\Unit\Poi;

use App\Osm\PoiRepositoryInterface;
use App\Poi\OsmPoiSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OsmPoiSourceTest extends TestCase
{
    /**
     * @param list<array{name: ?string, category: string, lat: float, lon: float}> $rows
     */
    private function source(array $rows): OsmPoiSource
    {
        $repository = $this->createStub(PoiRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn($rows);

        return new OsmPoiSource($repository);
    }

    /**
     * @return list<array{lat: float, lon: float}>
     */
    private function route(): array
    {
        return [['lat' => 48.0, 'lon' => 2.0]];
    }

    #[Test]
    public function mapsRepositoryRowToCandidate(): void
    {
        $poi = $this->source([
            ['name' => 'Boulangerie du Centre', 'category' => 'bakery', 'lat' => 48.1, 'lon' => 2.1],
        ])->fetchInCorridor($this->route(), 2000)[0];

        self::assertSame('Boulangerie du Centre', $poi['name']);
        self::assertSame('bakery', $poi['category']);
        self::assertSame(48.1, $poi['lat']);
        self::assertSame(2.1, $poi['lon']);
        self::assertNull($poi['wikidataId']);
        self::assertSame('osm', $poi['source']);
    }

    #[Test]
    public function nullNameIsNotReplacedByTheCategorySlug(): void
    {
        // "supermarket" is an OSM tag value, not a name: it must never reach the
        // rider, and it must not make two anonymous shops look identical to the
        // deduplicator (issue #874).
        $poi = $this->source([
            ['name' => null, 'category' => 'supermarket', 'lat' => 48.1, 'lon' => 2.1],
        ])->fetchInCorridor($this->route(), 2000)[0];

        self::assertNull($poi['name']);
        self::assertSame('supermarket', $poi['category']);
    }
}
