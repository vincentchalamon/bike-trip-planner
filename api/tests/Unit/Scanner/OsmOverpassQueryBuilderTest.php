<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\ApiResource\Model\Coordinate;
use App\Scanner\OsmOverpassQueryBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OsmOverpassQueryBuilderTest extends TestCase
{
    private OsmOverpassQueryBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        $this->builder = new OsmOverpassQueryBuilder();
    }

    #[Test]
    public function buildPoiQueryContainsExpectedStructure(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.1, 5.1),
        ];

        $query = $this->builder->buildPoiQuery($points);

        $this->assertStringContainsString('[out:json][timeout:15]', $query);
        $this->assertStringContainsString('amenity', $query);
        $this->assertStringContainsString('shop', $query);
        $this->assertStringContainsString('tourism', $query);
        $this->assertStringContainsString('out center 200', $query);
        $this->assertStringContainsString('around:2000', $query);
    }

    #[Test]
    public function buildPoiQueryContainsCorrectAmenities(): void
    {
        $points = [new Coordinate(45.0, 5.0)];
        $query = $this->builder->buildPoiQuery($points);

        $this->assertStringContainsString('restaurant', $query);
        $this->assertStringContainsString('cafe', $query);
        $this->assertStringContainsString('bar', $query);
        $this->assertStringContainsString('pharmacy', $query);
        $this->assertStringContainsString('fast_food', $query);
        $this->assertStringContainsString('marketplace', $query);
    }

    #[Test]
    public function buildPoiQueryContainsCorrectShops(): void
    {
        $points = [new Coordinate(45.0, 5.0)];
        $query = $this->builder->buildPoiQuery($points);

        $this->assertStringContainsString('convenience', $query);
        $this->assertStringContainsString('supermarket', $query);
        $this->assertStringContainsString('bakery', $query);
        $this->assertStringContainsString('butcher', $query);
    }

    #[Test]
    public function buildPoiQueryContainsPolyline(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.1, 5.1),
        ];

        $query = $this->builder->buildPoiQuery($points);

        $this->assertStringContainsString('45.000000,5.000000', $query);
        $this->assertStringContainsString('45.100000,5.100000', $query);
    }

    #[Test]
    public function buildAccommodationQueryUsesLargerRadius(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildAccommodationQuery($points);

        $this->assertStringContainsString('around:5000', $query);
        $this->assertStringContainsString('out center 100', $query);
    }

    #[Test]
    public function buildAccommodationQueryUsesCustomRadius(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildAccommodationQuery($points, 7000);

        $this->assertStringContainsString('around:7000', $query);
        $this->assertStringNotContainsString('around:5000', $query);
    }

    #[Test]
    public function buildAccommodationQueryGeneratesIndividualCirclesPerEndPoint(): void
    {
        $points = [
            new Coordinate(48.5, 2.5),
            new Coordinate(49.0, 3.0),
        ];

        $query = $this->builder->buildAccommodationQuery($points, 5000);

        // Each endpoint gets its own around: filter — not a corridor polyline
        $this->assertStringContainsString('(around:5000,48.500000,2.500000)', $query);
        $this->assertStringContainsString('(around:5000,49.000000,3.000000)', $query);
        // The two filters must be separate statements (not a single around with all coords)
        $this->assertStringNotContainsString('around:5000,48.500000,2.500000,49.000000,3.000000', $query);
    }

    #[Test]
    public function buildAccommodationQueryContainsAccommodationTypes(): void
    {
        $points = [new Coordinate(45.0, 5.0)];
        $query = $this->builder->buildAccommodationQuery($points);

        $this->assertStringContainsString('camp_site', $query);
        $this->assertStringContainsString('hostel', $query);
        $this->assertStringContainsString('hotel', $query);
        $this->assertStringContainsString('motel', $query);
        $this->assertStringContainsString('guest_house', $query);
        $this->assertStringContainsString('chalet', $query);
        $this->assertStringContainsString('alpine_hut', $query);
    }

    #[Test]
    public function buildAccommodationQueryWithEnabledTypesFiltersToRequestedTypes(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildAccommodationQuery($points, 5000, ['camp_site', 'hostel']);

        $this->assertStringContainsString('camp_site', $query);
        $this->assertStringContainsString('hostel', $query);
        $this->assertStringNotContainsString('hotel', $query);
        $this->assertStringNotContainsString('motel', $query);
        $this->assertStringNotContainsString('guest_house', $query);
        $this->assertStringNotContainsString('chalet', $query);
        $this->assertStringNotContainsString('alpine_hut', $query);
    }

    #[Test]
    public function buildAccommodationQueryWithSingleTypeUsesOnlyThatType(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildAccommodationQuery($points, 5000, ['hotel']);

        $this->assertStringContainsString('"tourism"~"^(hotel)$"', $query);
        $this->assertStringNotContainsString('camp_site', $query);
    }

    #[Test]
    public function buildBikeShopQuery(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildBikeShopQuery($points);

        $this->assertStringContainsString('[out:json][timeout:15]', $query);
        $this->assertStringContainsString('"shop"="bicycle"', $query);
        $this->assertStringContainsString('"service:bicycle:repair"="yes"', $query);
        $this->assertStringContainsString('around:2000', $query);
        $this->assertStringContainsString('out center tags 50', $query);
    }

    #[Test]
    public function buildBatchPoiQueryMergesAllStages(): void
    {
        $stage1 = [new Coordinate(45.0, 5.0), new Coordinate(45.1, 5.1)];
        $stage2 = [new Coordinate(46.0, 6.0), new Coordinate(46.1, 6.1)];

        $query = $this->builder->buildBatchPoiQuery([$stage1, $stage2]);

        // All points from both stages should be in the polyline
        $this->assertStringContainsString('45.000000,5.000000', $query);
        $this->assertStringContainsString('46.000000,6.000000', $query);
    }

    #[Test]
    public function buildCemeteryQueryContainsCemeteryTags(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildCemeteryQuery($points);

        $this->assertStringContainsString('[out:json][timeout:15]', $query);
        $this->assertStringContainsString('"landuse"="cemetery"', $query);
        $this->assertStringContainsString('"amenity"="grave_yard"', $query);
        $this->assertStringContainsString('around:2000', $query);
        $this->assertStringContainsString('out center 500', $query);
    }

    #[Test]
    public function buildWaysQueryContainsHighwayFilter(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildWaysQuery($points);

        $this->assertStringContainsString('[out:json][timeout:25]', $query);
        $this->assertStringContainsString('way["highway"~"^(primary|secondary|tertiary|unclassified|residential|living_street|service|track|path|cycleway|footway|bridleway)$"]', $query);
        $this->assertStringContainsString('around:100', $query);
        $this->assertStringContainsString('out tags geom qt', $query);
    }

    #[Test]
    public function buildWaysQueryContainsPolyline(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.1, 5.1),
        ];

        $query = $this->builder->buildWaysQuery($points);

        $this->assertStringContainsString('45.000000,5.000000', $query);
        $this->assertStringContainsString('45.100000,5.100000', $query);
    }

    #[Test]
    public function buildBatchCulturalPoiQueryMergesAllStages(): void
    {
        $stage1 = [new Coordinate(45.0, 5.0), new Coordinate(45.1, 5.1)];
        $stage2 = [new Coordinate(46.0, 6.0), new Coordinate(46.1, 6.1)];

        $query = $this->builder->buildBatchCulturalPoiQuery([$stage1, $stage2]);

        // All points from both stages should be in the polyline
        $this->assertStringContainsString('45.000000,5.000000', $query);
        $this->assertStringContainsString('46.000000,6.000000', $query);
        $this->assertStringContainsString('"tourism"="museum"', $query);
        $this->assertStringContainsString('"historic"~"^(castle|monument|memorial|ruins|archaeological_site|church|cathedral|abbey|fort)$"', $query);
        $this->assertStringContainsString('out center tags 100', $query);
    }

    #[Test]
    public function buildBatchCulturalPoiQueryUsesCustomRadius(): void
    {
        $stage1 = [new Coordinate(45.0, 5.0)];

        $query = $this->builder->buildBatchCulturalPoiQuery([$stage1], 1000);

        $this->assertStringContainsString('around:1000', $query);
        $this->assertStringNotContainsString('around:500', $query);
    }

    #[Test]
    public function buildBatchBikeShopQueryMergesAllStages(): void
    {
        $stage1 = [new Coordinate(45.0, 5.0)];
        $stage2 = [new Coordinate(46.0, 6.0)];

        $query = $this->builder->buildBatchBikeShopQuery([$stage1, $stage2]);

        $this->assertStringContainsString('45.000000,5.000000', $query);
        $this->assertStringContainsString('46.000000,6.000000', $query);
        $this->assertStringContainsString('"shop"="bicycle"', $query);
        $this->assertStringContainsString('"service:bicycle:repair"="yes"', $query);
        $this->assertStringContainsString('out center tags 50', $query);
    }
}
