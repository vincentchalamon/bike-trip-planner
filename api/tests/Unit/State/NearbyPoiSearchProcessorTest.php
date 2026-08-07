<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\GeoPosition;
use App\ApiResource\NearbyPoiSearchRequest;
use App\Entity\User;
use App\Geo\HaversineDistance;
use App\InRide\DeeplinkBuilder;
use App\InRide\DetourCalculator;
use App\InRide\InRidePoiCategory;
use App\InRide\InRidePoiRepositoryInterface;
use App\InRide\NearbyPoiFinder;
use App\InRide\OpeningHoursParser;
use App\InRide\RouteTail;
use App\Osm\CoverageRepositoryInterface;
use App\Poi\PoiLabelResolver;
use App\Repository\TripRequestRepositoryInterface;
use App\State\NearbyPoiSearchProcessor;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class NearbyPoiSearchProcessorTest extends TestCase
{
    use AlertMessageTestTrait;

    #[Test]
    public function rejectsWith429WhenTheLimiterIsExhausted(): void
    {
        $user = new User('rider@example.com');

        $limiter = new RateLimiterFactory(
            ['id' => 'nearby_pois', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $limiter->create($user->getId()->toRfc4122())->consume();

        $processor = new NearbyPoiSearchProcessor($this->finder([]), $this->security($user), $limiter);

        $this->expectException(TooManyRequestsHttpException::class);
        $processor->process($this->request(InRidePoiCategory::WATER), new Post(), ['id' => 'trip-1']);
    }

    #[Test]
    public function mapsTheFinderEnvelopeAndSuggestionsToTheResponse(): void
    {
        $finder = $this->finder([
            ['osmType' => 'node', 'osmId' => 1, 'name' => 'Fountain', 'category' => 'drinking_water', 'lat' => 48.0, 'lon' => 2.0, 'openingHours' => null, 'tags' => []],
        ]);

        $processor = new NearbyPoiSearchProcessor($finder, $this->security(new User('rider@example.com')), $this->freshLimiter());

        $response = $processor->process($this->request(InRidePoiCategory::WATER), new Post(), ['id' => 'trip-1']);

        self::assertSame('trip-1', $response->tripId);
        self::assertSame(InRidePoiCategory::WATER, $response->category);
        self::assertFalse($response->outOfCoverage);
        self::assertSame(1, $response->totalFound);
        self::assertCount(1, $response->pois);
        self::assertSame('Fountain', $response->pois[0]->name);
        self::assertSame(InRidePoiCategory::WATER, $response->pois[0]->category);
        self::assertStringContainsString('travelmode=bicycling', $response->pois[0]->deeplink);
    }

    #[Test]
    public function usesTheCategoryDefaultRadiusWhenNoneIsRequested(): void
    {
        $processor = new NearbyPoiSearchProcessor($this->finder([]), $this->security(new User('rider@example.com')), $this->freshLimiter());

        $response = $processor->process($this->request(InRidePoiCategory::WATER, null), new Post(), ['id' => 'trip-1']);

        self::assertSame(InRidePoiCategory::WATER->defaultRadiusMeters(), $response->radiusMeters);
    }

    #[Test]
    public function clampsAnOutOfBoundsRadiusWithoutErrorAndEchoesTheAppliedValue(): void
    {
        $processor = new NearbyPoiSearchProcessor($this->finder([]), $this->security(new User('rider@example.com')), $this->freshLimiter());

        $tooWide = $processor->process($this->request(InRidePoiCategory::WATER, 999_999), new Post(), ['id' => 'trip-1']);
        self::assertSame(InRidePoiCategory::MAX_RADIUS_METERS, $tooWide->radiusMeters);

        $tooNarrow = $processor->process($this->request(InRidePoiCategory::WATER, 10), new Post(), ['id' => 'trip-1']);
        self::assertSame(InRidePoiCategory::MIN_RADIUS_METERS, $tooNarrow->radiusMeters);
    }

    private function request(InRidePoiCategory $category, ?int $radiusMeters = 3_000): NearbyPoiSearchRequest
    {
        return new NearbyPoiSearchRequest($category, new GeoPosition(48.0, 2.0), $radiusMeters);
    }

    private function security(User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function freshLimiter(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'nearby_pois', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    /**
     * @param list<array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>}> $rows
     */
    private function finder(array $rows): NearbyPoiFinder
    {
        $distance = new HaversineDistance();

        $repo = $this->createStub(InRidePoiRepositoryInterface::class);
        $repo->method('findNearby')->willReturn($rows);

        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn(false);

        $trip = $this->createStub(TripRequestRepositoryInterface::class);
        $trip->method('getLocale')->willReturn('en');
        $trip->method('getStageGeometry')->willReturn(null);

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
