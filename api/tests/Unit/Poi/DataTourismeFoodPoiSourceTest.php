<?php

declare(strict_types=1);

namespace App\Tests\Unit\Poi;

use App\Poi\DataTourismeFoodPoiSource;
use App\Tourism\FoodPoiRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataTourismeFoodPoiSourceTest extends TestCase
{
    /**
     * @param list<array{name: ?string, category: string, lat: float, lon: float, openingHours: ?string, description: ?string, wikidata: ?string}> $rows
     */
    private function source(array $rows): DataTourismeFoodPoiSource
    {
        $repository = $this->createStub(FoodPoiRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn($rows);

        return new DataTourismeFoodPoiSource($repository);
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
            ['name' => 'Chez Paul', 'category' => 'restaurant', 'lat' => 48.1, 'lon' => 2.1, 'openingHours' => null, 'description' => null, 'wikidata' => 'Q42'],
        ])->fetchInCorridor($this->route(), 2000)[0];

        self::assertSame('Chez Paul', $poi['name']);
        self::assertSame('restaurant', $poi['category']);
        self::assertSame('Q42', $poi['wikidataId']);
        self::assertSame('datatourisme', $poi['source']);
    }

    #[Test]
    public function nullNameIsNotReplacedByTheCategorySlug(): void
    {
        // See OsmPoiSourceTest: the fallback label is resolved downstream, after
        // deduplication (issue #874).
        $poi = $this->source([
            ['name' => null, 'category' => 'restaurant', 'lat' => 48.1, 'lon' => 2.1, 'openingHours' => null, 'description' => null, 'wikidata' => null],
        ])->fetchInCorridor($this->route(), 2000)[0];

        self::assertNull($poi['name']);
        self::assertSame('restaurant', $poi['category']);
    }
}
