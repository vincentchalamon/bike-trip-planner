<?php

declare(strict_types=1);

namespace App\Tests\Unit\Accommodation;

use App\Accommodation\CandidateRanker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CandidateRankerTest extends TestCase
{
    private CandidateRanker $ranker;

    #[\Override]
    protected function setUp(): void
    {
        $this->ranker = new CandidateRanker();
    }

    #[Test]
    public function ranksTheDocumentedCandidateAboveTheCheaperBareOne(): void
    {
        $ranked = $this->ranker->rank([
            $this->candidate('Bivouac', 'wilderness_hut', priceMin: 0.0),
            $this->candidate(
                'Hotel',
                'hotel',
                priceMin: 90.0,
                url: 'https://hotel.example',
                hasWebsite: true,
                description: 'Hôtel avec garage à vélos',
                stars: 3,
            ),
        ], 5);

        self::assertSame(['Hotel', 'Bivouac'], array_column($ranked, 'name'));
    }

    #[Test]
    public function pricesBreakCompletenessTies(): void
    {
        $ranked = $this->ranker->rank([
            $this->candidate('Gîte cher', 'guest_house', priceMin: 80.0),
            $this->candidate('Gîte abordable', 'guest_house', priceMin: 40.0),
        ], 5);

        self::assertSame(['Gîte abordable', 'Gîte cher'], array_column($ranked, 'name'));
    }

    #[Test]
    public function tagRichnessCannotOutweighADescribedEntry(): void
    {
        // 30 OSM tags are capped at 2 points, below the 3 points of a description:
        // the curated DataTourisme entry is not evicted by tag volume alone (#869).
        $ranked = $this->ranker->rank([
            $this->candidate('OSM verbeux', 'hotel', priceMin: 50.0, tagCount: 30),
            $this->candidate('Fiche décrite', 'hotel', priceMin: 50.0, description: 'Une vraie description'),
        ], 5);

        self::assertSame(['Fiche décrite', 'OSM verbeux'], array_column($ranked, 'name'));
    }

    #[Test]
    public function reservesOneSlotForTheOtherFamily(): void
    {
        $candidates = [];
        foreach (range(1, 6) as $i) {
            $candidates[] = $this->candidate(\sprintf('Hotel %d', $i), 'hotel', priceMin: 50.0, hasWebsite: true, description: 'Décrit');
        }

        $candidates[] = $this->candidate('Camping', 'camp_site', priceMin: 12.0);

        $names = array_column($this->ranker->rank($candidates, 5), 'name');

        self::assertCount(5, $names);
        self::assertContains('Camping', $names);
        self::assertCount(4, array_filter($names, static fn (string $name): bool => str_starts_with($name, 'Hotel ')));
    }

    #[Test]
    public function givesTheReservedSlotBackWhenOnlyOneFamilyIsAround(): void
    {
        $candidates = [];
        foreach (range(1, 6) as $i) {
            $candidates[] = $this->candidate(\sprintf('Hotel %d', $i), 'hotel', priceMin: 50.0 + $i);
        }

        $names = array_column($this->ranker->rank($candidates, 5), 'name');

        self::assertSame(['Hotel 1', 'Hotel 2', 'Hotel 3', 'Hotel 4', 'Hotel 5'], $names);
    }

    #[Test]
    public function keepsEveryCandidateWhenFewerThanTheLimit(): void
    {
        $ranked = $this->ranker->rank([
            $this->candidate('Camping', 'camp_site', priceMin: 12.0),
            $this->candidate('Hotel', 'hotel', priceMin: 50.0),
        ], 5);

        self::assertCount(2, $ranked);
    }

    #[Test]
    public function isStableOnFullTiesSoTwoRunsRetainTheSameSet(): void
    {
        $candidates = [];
        foreach (range(1, 8) as $i) {
            $candidates[] = $this->candidate(\sprintf('Gîte %d', $i), 'guest_house', priceMin: 40.0);
        }

        $first = array_column($this->ranker->rank($candidates, 5), 'name');
        $second = array_column($this->ranker->rank($candidates, 5), 'name');

        self::assertSame($first, $second);
        // Full ties keep the caller's order, which is the deterministic KNN order
        // of the repositories (nearest first, primary-key tiebreak; #868).
        self::assertSame(['Gîte 1', 'Gîte 2', 'Gîte 3', 'Gîte 4', 'Gîte 5'], $first);
    }

    #[Test]
    public function scoresStarsAndCapacityFromTheIndexedColumns(): void
    {
        $ranked = $this->ranker->rank([
            $this->candidate('Sans données', 'hotel', priceMin: 50.0),
            $this->candidate('Avec étoiles et capacité', 'hotel', priceMin: 50.0, stars: 2, capacity: 40),
        ], 5);

        self::assertSame(['Avec étoiles et capacité', 'Sans données'], array_column($ranked, 'name'));
    }

    /**
     * @return array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, stars: ?int, capacity: ?int, fee: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string}
     */
    private function candidate(
        string $name,
        string $type,
        float $priceMin,
        ?string $url = null,
        bool $hasWebsite = false,
        ?string $description = null,
        ?string $openingHours = null,
        ?string $wikidataId = null,
        ?int $stars = null,
        ?int $capacity = null,
        int $tagCount = 2,
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'lat' => 48.6,
            'lon' => 2.6,
            'priceMin' => $priceMin,
            'priceMax' => $priceMin + 10.0,
            'isExact' => false,
            'url' => $url,
            'stars' => $stars,
            'capacity' => $capacity,
            'fee' => null,
            'tagCount' => $tagCount,
            'hasWebsite' => $hasWebsite,
            'tags' => [],
            'source' => 'osm',
            'wikidataId' => $wikidataId,
            'description' => $description,
            'imageUrl' => null,
            'wikipediaUrl' => null,
            'openingHours' => $openingHours,
        ];
    }
}
