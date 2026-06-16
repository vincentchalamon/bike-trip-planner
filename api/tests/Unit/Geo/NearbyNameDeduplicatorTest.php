<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\GeoDistanceInterface;
use App\Geo\NearbyNameDeduplicator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NearbyNameDeduplicatorTest extends TestCase
{
    private function deduplicator(float $distanceMeters): NearbyNameDeduplicator
    {
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn($distanceMeters);

        return new NearbyNameDeduplicator($haversine);
    }

    /**
     * @return array{name: string, lat: float, lon: float, wikidataId: string|null, source: string}
     */
    private function item(string $name, string $source, ?string $wikidataId = null): array
    {
        return ['name' => $name, 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => $wikidataId, 'source' => $source];
    }

    #[Test]
    public function prefersDataTourismeOnASharedWikidataId(): void
    {
        foreach ([['osm', 'datatourisme'], ['datatourisme', 'osm']] as [$first, $second]) {
            $result = $this->deduplicator(9_999.0)->dedupe([
                $this->item('Louvre', $first, 'Q19675'),
                $this->item('Louvre', $second, 'Q19675'),
            ]);

            self::assertCount(1, $result);
            self::assertSame('datatourisme', $result[0]['source'], "order: $first, $second");
        }
    }

    #[Test]
    public function mergesTheSameNameWithinProximity(): void
    {
        $result = $this->deduplicator(50.0)->dedupe([
            $this->item('Musée du Lac', 'osm'),
            $this->item('Musée du Lac', 'datatourisme'),
        ]);

        self::assertCount(1, $result);
        self::assertSame('datatourisme', $result[0]['source']);
    }

    #[Test]
    public function normalisesAccentsAndCaseWhenComparingNames(): void
    {
        $result = $this->deduplicator(50.0)->dedupe([
            $this->item('Château Fort', 'osm'),
            $this->item('chateau  fort', 'datatourisme'),
        ]);

        self::assertCount(1, $result);
        self::assertSame('datatourisme', $result[0]['source']);
    }

    #[Test]
    public function keepsTheSameNameBeyondProximity(): void
    {
        $result = $this->deduplicator(1_000.0)->dedupe([
            $this->item('Musée du Lac', 'osm'),
            $this->item('Musée du Lac', 'datatourisme'),
        ]);

        self::assertCount(2, $result);
    }

    #[Test]
    public function keepsDifferentNamesEvenWhenColocated(): void
    {
        $result = $this->deduplicator(5.0)->dedupe([
            $this->item('Alpha', 'osm'),
            $this->item('Beta', 'datatourisme'),
        ]);

        self::assertCount(2, $result);
    }

    #[Test]
    public function neverMergesEmptyNames(): void
    {
        $result = $this->deduplicator(5.0)->dedupe([
            $this->item('', 'osm'),
            $this->item('', 'datatourisme'),
        ]);

        self::assertCount(2, $result);
    }
}
