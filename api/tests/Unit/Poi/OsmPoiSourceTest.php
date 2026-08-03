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
     * @param list<array{name: ?string, category: string, lat: float, lon: float, osmType?: ?string, osmId?: ?int, openingHours: ?string, website: ?string}> $rows
     */
    private function source(array $rows): OsmPoiSource
    {
        $repository = $this->createStub(PoiRepositoryInterface::class);
        // The repository always states the OSM identity; fixtures declare it only
        // when the case is about it.
        $repository->method('findInCorridor')->willReturn(
            array_map(static fn (array $r): array => $r + ['osmType' => null, 'osmId' => null], $rows),
        );

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
            ['name' => 'Boulangerie du Centre', 'category' => 'bakery', 'lat' => 48.1, 'lon' => 2.1, 'osmType' => 'node', 'osmId' => 12, 'openingHours' => null, 'website' => null],
        ])->fetchInCorridor($this->route(), 2000)[0];

        self::assertSame('Boulangerie du Centre', $poi['name']);
        self::assertSame('bakery', $poi['category']);
        self::assertSame(48.1, $poi['lat']);
        self::assertSame(2.1, $poi['lon']);
        self::assertNull($poi['wikidataId']);
        self::assertSame('osm', $poi['source']);
        // Tier-1 primary key: without it the "see on OSM" link cannot be built.
        self::assertSame('node', $poi['osmType']);
        self::assertSame(12, $poi['osmId']);
    }

    #[Test]
    public function nullNameIsNotReplacedByTheCategorySlug(): void
    {
        // "supermarket" is an OSM tag value, not a name: it must never reach the
        // rider, and it must not make two anonymous shops look identical to the
        // deduplicator (issue #874).
        $poi = $this->source([
            ['name' => null, 'category' => 'supermarket', 'lat' => 48.1, 'lon' => 2.1, 'openingHours' => null, 'website' => null],
        ])->fetchInCorridor($this->route(), 2000)[0];

        self::assertNull($poi['name']);
        self::assertSame('supermarket', $poi['category']);
    }
}
