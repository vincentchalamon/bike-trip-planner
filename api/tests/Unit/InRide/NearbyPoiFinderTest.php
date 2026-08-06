<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\Geo\GeoPoint;
use App\Geo\HaversineDistance;
use App\InRide\DeeplinkBuilder;
use App\InRide\DetourCalculator;
use App\InRide\InRidePoiCategory;
use App\InRide\InRidePoiRepositoryInterface;
use App\InRide\NearbyPoiFinder;
use App\InRide\OpeningHoursParser;
use App\InRide\PoiSuggestion;
use App\InRide\PoiWarning;
use App\InRide\RouteTail;
use App\Osm\CoverageRepositoryInterface;
use App\Poi\PoiLabelResolver;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NearbyPoiFinderTest extends TestCase
{
    use AlertMessageTestTrait;

    private const array RIDER = [48.0, 2.0];

    // Fixed midday so a `24/7` venue is never within 30 min of its next-midnight
    // close, which would otherwise flake into a CLOSES_SOON warning.
    private const string NOON = '2026-08-06 12:00:00';

    // A straight westbound-to-eastbound route the rider sits at the start of.
    private const array ROUTE = [
        ['lat' => 48.0, 'lon' => 2.0],
        ['lat' => 48.0, 'lon' => 2.05],
    ];

    /**
     * @return iterable<string, array{InRidePoiCategory}>
     */
    public static function nameRequiringCategories(): iterable
    {
        yield 'food' => [InRidePoiCategory::FOOD];
        yield 'mechanic' => [InRidePoiCategory::MECHANIC];
        yield 'health' => [InRidePoiCategory::HEALTH];
        yield 'train' => [InRidePoiCategory::TRAIN];
    }

    #[DataProvider('nameRequiringCategories')]
    #[Test]
    public function discardsNamelessEntryForNameRequiringCategories(InRidePoiCategory $category): void
    {
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmId' => 1, 'name' => null]),
            $this->row(['osmId' => 2, 'name' => 'Chez Bob']),
        ]));

        $result = $finder->find($category, $this->rider());

        self::assertSame(1, $result['totalFound']);
        self::assertCount(1, $result['pois']);
        self::assertSame('Chez Bob', $result['pois'][0]->name);
    }

    /**
     * @return iterable<string, array{InRidePoiCategory, string, string}>
     */
    public static function nameOptionalCategories(): iterable
    {
        yield 'water' => [InRidePoiCategory::WATER, 'drinking_water', 'Drinking water'];
        yield 'shelter' => [InRidePoiCategory::SHELTER, 'shelter', 'Shelter'];
        yield 'charging' => [InRidePoiCategory::CHARGING, 'charging_station', 'Charging station'];
    }

    #[DataProvider('nameOptionalCategories')]
    #[Test]
    public function keepsAndLabelsNamelessEntryForNameOptionalCategories(InRidePoiCategory $category, string $osmCategory, string $expectedLabel): void
    {
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmId' => 1, 'name' => null, 'category' => $osmCategory]),
        ]));

        $result = $finder->find($category, $this->rider());

        self::assertSame(1, $result['totalFound']);
        self::assertSame($expectedLabel, $result['pois'][0]->name);
    }

    #[Test]
    public function labelsBusShelterDistinctlyFromAGenericShelter(): void
    {
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmId' => 1, 'name' => null, 'category' => 'shelter']),
            $this->row(['osmId' => 2, 'name' => null, 'category' => 'shelter', 'lat' => 48.001, 'tags' => ['shelter_type' => 'public_transport']]),
        ]));

        $result = $finder->find(InRidePoiCategory::SHELTER, $this->rider());

        $names = array_map(static fn (PoiSuggestion $poi): string => $poi->name, $result['pois']);
        self::assertContains('Shelter', $names);
        self::assertContains('Bus shelter', $names);
    }

    #[Test]
    public function keepsMissingAndUnreadableHoursAsUnverifiedButDiscardsCertainlyClosed(): void
    {
        $sunday = new \DateTimeImmutable('2026-08-09 12:00:00');
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmId' => 1, 'name' => 'No tag', 'openingHours' => null]),
            $this->row(['osmId' => 2, 'name' => 'Garbage tag', 'openingHours' => 'garbage data here']),
            $this->row(['osmId' => 3, 'name' => 'Closed today', 'openingHours' => 'Mo-Fr 09:00-17:00']),
        ]));

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider(), now: $sunday);

        self::assertSame(2, $result['totalFound']);
        $byName = $this->byName($result['pois']);
        self::assertSame(PoiWarning::HOURS_UNVERIFIED, $byName['No tag']->warning);
        self::assertSame(PoiWarning::HOURS_UNVERIFIED, $byName['Garbage tag']->warning);
        self::assertArrayNotHasKey('Closed today', $byName);
    }

    #[Test]
    public function flagsAVenueClosingWithinThirtyMinutes(): void
    {
        // Explicit UTC: closesAt inherits $now's timezone, so pinning it here keeps the
        // ATOM assertion below stable whatever the runtime default timezone is (CI runs Europe/Paris).
        $now = new \DateTimeImmutable('2026-08-06 14:40:00', new \DateTimeZone('UTC'));
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmId' => 1, 'name' => 'Closing soon', 'openingHours' => '08:00-15:00']),
            $this->row(['osmId' => 2, 'name' => 'Open late', 'openingHours' => '08:00-23:00', 'lat' => 48.001]),
        ]));

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider(), now: $now);

        $byName = $this->byName($result['pois']);
        self::assertSame(PoiWarning::CLOSES_SOON, $byName['Closing soon']->warning);
        self::assertSame(20, $byName['Closing soon']->warningMinutes);
        self::assertSame('2026-08-06T15:00:00+00:00', $byName['Closing soon']->closesAt?->format(\DateTimeInterface::ATOM));
        self::assertNull($byName['Open late']->warning);
    }

    #[Test]
    public function shortCircuitsWhenOutOfCoverageWithoutQueryingPois(): void
    {
        $repo = $this->createMock(InRidePoiRepositoryInterface::class);
        $repo->expects(self::never())->method('findNearby');

        $result = $this->finder($repo, outOfZone: true)->find(InRidePoiCategory::WATER, $this->rider());

        self::assertTrue($result['outOfCoverage']);
        self::assertSame([], $result['pois']);
        self::assertSame(0, $result['totalFound']);
        self::assertFalse($result['capReached']);
    }

    #[Test]
    public function reportsCapReachedOnlyWhenTheReaderReturnsExactlyTheCandidateLimit(): void
    {
        $limit = InRidePoiCategory::WATER->candidateLimit();

        $atCap = $this->finder($this->poiRepo($this->manyRows($limit)))->find(InRidePoiCategory::WATER, $this->rider());
        self::assertTrue($atCap['capReached']);
        self::assertSame($limit, $atCap['totalFound']);

        $belowCap = $this->finder($this->poiRepo($this->manyRows($limit - 1)))->find(InRidePoiCategory::WATER, $this->rider());
        self::assertFalse($belowCap['capReached']);
    }

    #[Test]
    public function totalFoundCountsRetainedRowsBeforeTheCutToThree(): void
    {
        $finder = $this->finder($this->poiRepo($this->manyRows(5)));

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider());

        self::assertSame(5, $result['totalFound']);
        self::assertCount(3, $result['pois']);
    }

    #[Test]
    public function deduplicatesOnOsmTypeAndId(): void
    {
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmType' => 'node', 'osmId' => 7, 'name' => 'First']),
            $this->row(['osmType' => 'node', 'osmId' => 7, 'name' => 'Duplicate']),
            $this->row(['osmType' => 'way', 'osmId' => 7, 'name' => 'Different type']),
        ]));

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider());

        self::assertSame(2, $result['totalFound']);
    }

    #[Test]
    public function leavesDetourNullAndRanksOnDistanceWithoutAStageDay(): void
    {
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmId' => 1, 'name' => 'Far', 'lat' => 48.0, 'lon' => 2.04]),
            $this->row(['osmId' => 2, 'name' => 'Near', 'lat' => 48.0, 'lon' => 2.01]),
        ]));

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider());

        self::assertSame('Near', $result['pois'][0]->name);
        foreach ($result['pois'] as $poi) {
            self::assertNull($poi->detourMeters);
        }
    }

    #[Test]
    public function computesDetourAndRanksDistancePlusTwiceDetourWithAStageDay(): void
    {
        // "Detour" is on-route (~0 detour) but farther in straight line; "Direct"
        // is closer but off-route, so 2×detour must push it below "Detour".
        $finder = $this->finder(
            $this->poiRepo([
                $this->row(['osmId' => 1, 'name' => 'Direct', 'lat' => 48.02, 'lon' => 2.005, 'openingHours' => '24/7']),
                $this->row(['osmId' => 2, 'name' => 'Detour', 'lat' => 48.0, 'lon' => 2.04, 'openingHours' => '24/7']),
            ]),
            geometry: self::ROUTE,
        );

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider(), tripId: 'trip-1', stageDay: 1, now: new \DateTimeImmutable(self::NOON));

        self::assertSame('Detour', $result['pois'][0]->name);
        foreach ($result['pois'] as $poi) {
            self::assertNotNull($poi->detourMeters);
        }
    }

    #[Test]
    public function flagsPoiFarFromRouteWhenNoWarningIsAlreadySet(): void
    {
        $finder = $this->finder(
            $this->poiRepo([
                $this->row(['osmId' => 1, 'name' => 'Off route', 'lat' => 48.1, 'lon' => 2.02, 'openingHours' => '24/7']),
            ]),
            geometry: self::ROUTE,
        );

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider(), tripId: 'trip-1', stageDay: 1, now: new \DateTimeImmutable(self::NOON));

        self::assertSame(PoiWarning::FAR_FROM_ROUTE, $result['pois'][0]->warning);
    }

    #[Test]
    public function farFromRouteNeverOverridesAnEarlierWarning(): void
    {
        $finder = $this->finder(
            $this->poiRepo([
                $this->row(['osmId' => 1, 'name' => 'Off route no tag', 'lat' => 48.1, 'lon' => 2.02, 'openingHours' => null]),
            ]),
            geometry: self::ROUTE,
        );

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider(), tripId: 'trip-1', stageDay: 1);

        self::assertSame(PoiWarning::HOURS_UNVERIFIED, $result['pois'][0]->warning);
    }

    #[Test]
    public function detourIsComputedOnAtMostTenClosestCandidates(): void
    {
        // Ten rows sit ~1 km north of the east-west route (a large, near-identical
        // detour), each closer in straight line than the eleventh. The eleventh,
        // "poi-10", sits exactly on the route (~0 detour) but slightly farther in
        // straight line, so it is the 11th-closest. The distance cut drops it
        // *before* any detour is computed; were the cap removed, its ~0 detour would
        // beat every off-route row's distance+2×detour and land it in the top 3.
        // Its absence therefore pins the 10-candidate cap, not mere distance order.
        $rows = [];
        for ($i = 0; $i < 10; ++$i) {
            $rows[] = $this->row(['osmId' => $i + 1, 'name' => 'poi-'.$i, 'lat' => 48.009, 'lon' => 2.0 + 0.0001 * $i, 'openingHours' => '24/7']);
        }

        $rows[] = $this->row(['osmId' => 11, 'name' => 'poi-10', 'lat' => 48.0, 'lon' => 2.014, 'openingHours' => '24/7']);

        $finder = $this->finder($this->poiRepo($rows), geometry: self::ROUTE);

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider(), tripId: 'trip-1', stageDay: 1, now: new \DateTimeImmutable(self::NOON));

        $names = array_map(static fn (PoiSuggestion $poi): string => $poi->name, $result['pois']);
        self::assertNotContains('poi-10', $names);
    }

    #[Test]
    public function doesNotReportTheLegacyTwiceRadiusGuard(): void
    {
        // Row 5 km from the rider with a 1 km radius: the deleted InRideAssistant
        // dropped anything past 2×radius in PHP; ST_DWithin already bounds it in
        // SQL (ADR-040), so the finder must keep whatever the reader returned.
        $finder = $this->finder($this->poiRepo([
            $this->row(['osmId' => 1, 'name' => 'Far fountain', 'lat' => 48.045, 'lon' => 2.0]),
        ]));

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider(), radiusMeters: 1000);

        self::assertSame(1, $result['totalFound']);
    }

    #[Test]
    public function fallsBackToEnglishLabelsWithoutATrip(): void
    {
        $finder = $this->finder(
            $this->poiRepo([$this->row(['osmId' => 1, 'name' => null, 'category' => 'shelter'])]),
            locale: null,
        );

        $result = $finder->find(InRidePoiCategory::SHELTER, $this->rider());

        self::assertSame('Shelter', $result['pois'][0]->name);
    }

    #[Test]
    public function buildsAGoogleMapsBicyclingDeeplink(): void
    {
        $finder = $this->finder($this->poiRepo([$this->row(['osmId' => 1, 'name' => 'Fountain'])]));

        $result = $finder->find(InRidePoiCategory::WATER, $this->rider());

        self::assertStringContainsString('travelmode=bicycling', $result['pois'][0]->deeplink);
        self::assertStringContainsString('origin=48,2', $result['pois'][0]->deeplink);
    }

    // --- fixtures -----------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>}
     */
    private function row(array $overrides = []): array
    {
        /** @var array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>} $row */
        $row = array_merge([
            'osmType' => 'node',
            'osmId' => 1,
            'name' => 'A place',
            'category' => 'drinking_water',
            'lat' => 48.0,
            'lon' => 2.0,
            'openingHours' => null,
            'tags' => [],
        ], $overrides);

        return $row;
    }

    /**
     * @return list<array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>}>
     */
    private function manyRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $rows[] = $this->row(['osmId' => $i + 1, 'name' => 'poi-'.$i, 'lon' => 2.0 + 0.0001 * $i]);
        }

        return $rows;
    }

    private function rider(): GeoPoint
    {
        return new GeoPoint(self::RIDER[0], self::RIDER[1]);
    }

    /**
     * @param list<PoiSuggestion> $pois
     *
     * @return array<string, PoiSuggestion>
     */
    private function byName(array $pois): array
    {
        $map = [];
        foreach ($pois as $poi) {
            $map[$poi->name] = $poi;
        }

        return $map;
    }

    /**
     * @param list<array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>}> $rows
     */
    private function poiRepo(array $rows): InRidePoiRepositoryInterface
    {
        $repo = $this->createStub(InRidePoiRepositoryInterface::class);
        $repo->method('findNearby')->willReturn($rows);

        return $repo;
    }

    /**
     * @param list<array{lat: float, lon: float}>|null $geometry
     */
    private function finder(
        InRidePoiRepositoryInterface $repo,
        bool $outOfZone = false,
        ?array $geometry = null,
        ?string $locale = 'en',
    ): NearbyPoiFinder {
        $distance = new HaversineDistance();

        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn($outOfZone);

        $trip = $this->createStub(TripRequestRepositoryInterface::class);
        $trip->method('getLocale')->willReturn($locale);
        $trip->method('getStageGeometry')->willReturn($geometry);

        return new NearbyPoiFinder(
            $repo,
            new OpeningHoursParser(),
            new DetourCalculator($distance),
            new RouteTail($distance),
            new DeeplinkBuilder(),
            $distance,
            $coverage,
            new PoiLabelResolver($this->createAlertTranslator()),
            $trip,
        );
    }
}
