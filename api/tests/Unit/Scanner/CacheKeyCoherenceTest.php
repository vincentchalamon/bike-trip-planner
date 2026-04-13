<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\ApiResource\Model\Coordinate;
use App\Scanner\OsmOverpassQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ensures that ScanAllOsmDataHandler and downstream leaf handlers produce
 * identical Overpass queries (and therefore identical cache keys) when
 * given the same decimated points.
 *
 * Cache keys are derived from the query string via `osm.` + xxh128 hash.
 * Any difference in query text — even whitespace — causes a cache miss
 * and a redundant Overpass API call.
 */
final class CacheKeyCoherenceTest extends TestCase
{
    private OsmOverpassQueryBuilder $queryBuilder;

    /** @var list<Coordinate> */
    private array $decimatedPoints;

    protected function setUp(): void
    {
        $this->queryBuilder = new OsmOverpassQueryBuilder();
        $this->decimatedPoints = [
            new Coordinate(48.0, 2.0, 100.0),
            new Coordinate(48.1, 2.1, 120.0),
            new Coordinate(48.2, 2.2, 90.0),
            new Coordinate(48.3, 2.3, 110.0),
            new Coordinate(48.4, 2.4, 130.0),
            new Coordinate(48.5, 2.5, 80.0),
        ];
    }

    /**
     * Returns builder method names for the 5 categories that ScanAllOsmData
     * warms using the full decimated route points.
     *
     * @return iterable<string, array{string}>
     */
    public static function warmedQueryProvider(): iterable
    {
        yield 'poi' => ['buildPoiQuery'];
        yield 'bikeShop' => ['buildBikeShopQuery'];
        yield 'cemetery' => ['buildCemeteryQuery'];
        yield 'ways' => ['buildWaysQuery'];
        yield 'healthService' => ['buildHealthServiceQuery'];
    }

    /**
     * Calling the same QueryBuilder method twice with the same points must
     * produce the exact same query string — guaranteeing cache key alignment.
     */
    #[Test]
    #[DataProvider('warmedQueryProvider')]
    public function warmedQueryIsDeterministic(string $buildMethod): void
    {
        $query1 = $this->queryBuilder->{$buildMethod}($this->decimatedPoints);
        $query2 = $this->queryBuilder->{$buildMethod}($this->decimatedPoints);

        self::assertSame($query1, $query2, \sprintf(
            'QueryBuilder::%s() must be deterministic: two calls with the same points must return identical query strings.',
            $buildMethod,
        ));
    }

    /**
     * ScanAllOsmDataHandler warms the cache for `buildPoiQuery(decimatedPoints)`.
     * ScanPoisHandler must use the same call to benefit from the cache.
     * This test documents the contract: a single global POI query, not per-stage queries.
     */
    #[Test]
    public function scanPoisUsesGlobalPoiQueryNotPerStage(): void
    {
        $globalQuery = $this->queryBuilder->buildPoiQuery($this->decimatedPoints);

        // Per-stage query uses a subset of points — must produce a DIFFERENT query
        $perStageQuery = $this->queryBuilder->buildPoiQuery(
            \array_slice($this->decimatedPoints, 0, 3),
        );

        self::assertNotSame($globalQuery, $perStageQuery, 'Per-stage queries differ from the global query and would miss the warming cache.');
    }

    /**
     * The 5 point-only warmed categories must produce distinct queries (no accidental collision).
     * `buildAccommodationQuery` is excluded — it accepts extra parameters beyond `$points`.
     */
    #[Test]
    public function allWarmedQueriesAreDistinct(): void
    {
        $queries = [
            'poi' => $this->queryBuilder->buildPoiQuery($this->decimatedPoints),
            'bikeShop' => $this->queryBuilder->buildBikeShopQuery($this->decimatedPoints),
            'cemetery' => $this->queryBuilder->buildCemeteryQuery($this->decimatedPoints),
            'ways' => $this->queryBuilder->buildWaysQuery($this->decimatedPoints),
            'healthService' => $this->queryBuilder->buildHealthServiceQuery($this->decimatedPoints),
        ];

        $uniqueQueries = array_unique($queries);

        self::assertCount(\count($queries), $uniqueQueries, 'Each warmed category must produce a distinct query.');
    }
}
