<?php

declare(strict_types=1);

namespace App\Tests\Unit\AccommodationSource;

use App\AccommodationSource\AccommodationSourceInterface;
use App\AccommodationSource\AccommodationSourceRegistry;
use App\ApiResource\Model\Coordinate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccommodationSourceRegistryTest extends TestCase
{
    #[Test]
    public function fetchAllConcatenatesResultsFromAllEnabledSources(): void
    {
        $endPoints = [new Coordinate(48.5, 2.5)];

        $candidateA = $this->makeCandidate('Hotel A', 'osm');
        $candidateB = $this->makeCandidate('Hotel B', 'datatourisme');

        $sourceA = $this->createStub(AccommodationSourceInterface::class);
        $sourceA->method('isEnabled')->willReturn(true);
        $sourceA->method('fetch')->willReturn([$candidateA]);

        $sourceB = $this->createStub(AccommodationSourceInterface::class);
        $sourceB->method('isEnabled')->willReturn(true);
        $sourceB->method('fetch')->willReturn([$candidateB]);

        $registry = new AccommodationSourceRegistry([$sourceA, $sourceB]);
        $results = $registry->fetchAll($endPoints, 5000, ['hotel']);

        $this->assertCount(2, $results);
        $this->assertSame('Hotel A', $results[0]['name']);
        $this->assertSame('Hotel B', $results[1]['name']);
    }

    #[Test]
    public function fetchAllSkipsDisabledSources(): void
    {
        $endPoints = [new Coordinate(48.5, 2.5)];

        $candidateA = $this->makeCandidate('Hotel A', 'osm');

        $sourceA = $this->createStub(AccommodationSourceInterface::class);
        $sourceA->method('isEnabled')->willReturn(true);
        $sourceA->method('fetch')->willReturn([$candidateA]);

        $sourceB = $this->createMock(AccommodationSourceInterface::class);
        $sourceB->method('isEnabled')->willReturn(false);
        $sourceB->expects($this->never())->method('fetch');

        $registry = new AccommodationSourceRegistry([$sourceA, $sourceB]);
        $results = $registry->fetchAll($endPoints, 5000, ['hotel']);

        $this->assertCount(1, $results);
        $this->assertSame('Hotel A', $results[0]['name']);
    }

    #[Test]
    public function fetchAllReturnsEmptyArrayWhenNoSourcesEnabled(): void
    {
        $source = $this->createStub(AccommodationSourceInterface::class);
        $source->method('isEnabled')->willReturn(false);

        $registry = new AccommodationSourceRegistry([$source]);
        $results = $registry->fetchAll([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame([], $results);
    }

    #[Test]
    public function fetchAllReturnsEmptyArrayWhenNoSources(): void
    {
        $registry = new AccommodationSourceRegistry([]);
        $results = $registry->fetchAll([new Coordinate(48.5, 2.5)], 5000, ['hotel']);

        $this->assertSame([], $results);
    }

    #[Test]
    public function fetchAllPassesArgumentsToEachSource(): void
    {
        $endPoints = [new Coordinate(48.5, 2.5)];
        $radiusMeters = 10000;
        $enabledTypes = ['hotel', 'hostel'];

        $source = $this->createMock(AccommodationSourceInterface::class);
        $source->method('isEnabled')->willReturn(true);
        $source->expects($this->once())
            ->method('fetch')
            ->with($endPoints, $radiusMeters, $enabledTypes)
            ->willReturn([]);

        $registry = new AccommodationSourceRegistry([$source]);
        $registry->fetchAll($endPoints, $radiusMeters, $enabledTypes);
    }

    /**
     * @return array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}
     */
    private function makeCandidate(string $name, string $source): array
    {
        return [
            'name' => $name,
            'type' => 'hotel',
            'lat' => 48.6,
            'lon' => 2.6,
            'priceMin' => 50.0,
            'priceMax' => 120.0,
            'isExact' => false,
            'url' => null,
            'tagCount' => 0,
            'hasWebsite' => false,
            'tags' => [],
            'source' => $source,
            'wikidataId' => null,
        ];
    }
}
