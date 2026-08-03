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
            self::assertSame('datatourisme', $result[0]['source'], \sprintf('order: %s, %s', $first, $second));
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

    #[Test]
    public function keepsBothAnonymousPoisOfTheSameCategoryWithinProximity(): void
    {
        // Two nameless cafes 40 m apart are two businesses, not one: the
        // deduplicator must never fall back to the category to name them
        // (issue #874).
        $result = $this->deduplicator(40.0)->dedupe([
            ['name' => null, 'category' => 'cafe', 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'osm'],
            ['name' => null, 'category' => 'cafe', 'lat' => 48.0004, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'datatourisme'],
        ]);

        self::assertCount(2, $result);
    }

    #[Test]
    public function doesNotMergeItemsWithMissingCoordinates(): void
    {
        // Same name + colocated distance, but no lat/lon → coord() yields null and
        // isSamePlace() bails, so the entries are kept apart.
        $result = $this->deduplicator(5.0)->dedupe([
            ['name' => 'Camping', 'source' => 'osm', 'wikidataId' => null],
            ['name' => 'Camping', 'source' => 'datatourisme', 'wikidataId' => null],
        ]);

        self::assertCount(2, $result);
    }

    #[Test]
    public function theCuratedWinnerInheritsWhatOnlyTheOtherSourceKnew(): void
    {
        // The flux carries no website for cultural and food POIs, so preferring the
        // curated entry used to drop the OSM website for every place both sources
        // describe. The winner keeps its own values and only fills its own gaps.
        $result = $this->deduplicator(5.0)->dedupe([
            ['name' => 'Musée', 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'osm', 'website' => 'https://musee.test', 'openingHours' => 'Mo-Fr 09:00-17:00', 'description' => null],
            ['name' => 'Musée', 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'datatourisme', 'website' => null, 'openingHours' => null, 'description' => 'Collection permanente.'],
        ]);

        self::assertCount(1, $result);
        self::assertSame('datatourisme', $result[0]['source'], 'The curated entry still wins.');
        self::assertSame('Collection permanente.', $result[0]['description'], 'Its own values are untouched.');
        self::assertSame('https://musee.test', $result[0]['website'], 'The OSM website must survive the merge.');
        self::assertSame('Mo-Fr 09:00-17:00', $result[0]['openingHours']);
    }

    #[Test]
    public function theCuratedWinnerNeverLosesItsOwnValueToTheOtherSource(): void
    {
        $result = $this->deduplicator(5.0)->dedupe([
            ['name' => 'Musée', 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'osm', 'website' => 'https://osm.test'],
            ['name' => 'Musée', 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'datatourisme', 'website' => 'https://curated.test'],
        ]);

        self::assertCount(1, $result);
        self::assertSame('https://curated.test', $result[0]['website']);
    }

    #[Test]
    public function theBackfillNeverAddsAKeyTheWinnerDoesNotDeclare(): void
    {
        // Sources do not all share the same shape: a food POI has no description.
        // Filling gaps must not graft a foreign key onto the winner's contract.
        $result = $this->deduplicator(5.0)->dedupe([
            ['name' => 'Café', 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'osm', 'description' => 'Texte OSM'],
            ['name' => 'Café', 'lat' => 48.0, 'lon' => 2.0, 'wikidataId' => null, 'source' => 'datatourisme', 'website' => null],
        ]);

        self::assertCount(1, $result);
        self::assertArrayNotHasKey('description', $result[0]);
    }
}
