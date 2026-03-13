<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComputationTracker;

use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationDependencyResolver;
use App\Enum\ComputationName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComputationDependencyResolverTest extends TestCase
{
    private ComputationDependencyResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new ComputationDependencyResolver();
    }

    #[Test]
    public function noChangesReturnsEmpty(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/123');

        $this->assertSame([], $this->resolver->resolve($old, $new));
    }

    #[Test]
    public function sourceUrlChangeReturnsRoute(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/456');

        $result = $this->resolver->resolve($old, $new);

        $this->assertCount(1, $result);
        $this->assertSame(ComputationName::ROUTE, $result[0]);
    }

    #[Test]
    public function sourceUrlChangeCascadesEverythingViaRoute(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123', '2026-07-01', '2026-07-10');
        $new = $this->createRequest('https://www.komoot.com/tour/456', '2026-08-01', '2026-08-10');

        $result = $this->resolver->resolve($old, $new);

        // Even though dates changed too, ROUTE alone cascades everything
        $this->assertCount(1, $result);
        $this->assertSame(ComputationName::ROUTE, $result[0]);
    }

    #[Test]
    public function endDateChangeReturnsStages(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123', '2026-07-01', '2026-07-10');
        $new = $this->createRequest('https://www.komoot.com/tour/123', '2026-07-01', '2026-07-15');

        $result = $this->resolver->resolve($old, $new);

        $this->assertContains(ComputationName::STAGES, $result);
    }

    #[Test]
    public function fatigueFactorChangeReturnsStages(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/123');
        $old->fatigueFactor = 0.9;
        $new->fatigueFactor = 0.8;

        $result = $this->resolver->resolve($old, $new);

        $this->assertContains(ComputationName::STAGES, $result);
    }

    #[Test]
    public function elevationPenaltyChangeReturnsStages(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/123');
        $old->elevationPenalty = 50.0;
        $new->elevationPenalty = 40.0;

        $result = $this->resolver->resolve($old, $new);

        $this->assertContains(ComputationName::STAGES, $result);
    }

    #[Test]
    public function startDateChangeReturnsWeatherAndCalendar(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123', '2026-07-01');
        $new = $this->createRequest('https://www.komoot.com/tour/123', '2026-08-01');

        $result = $this->resolver->resolve($old, $new);

        $this->assertContains(ComputationName::WEATHER, $result);
        $this->assertContains(ComputationName::CALENDAR, $result);
        $this->assertNotContains(ComputationName::ROUTE, $result);
        $this->assertNotContains(ComputationName::STAGES, $result);
    }

    #[Test]
    public function multipleNonRouteChangesDeduplicates(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123', '2026-07-01', '2026-07-10');
        $new = $this->createRequest('https://www.komoot.com/tour/123', '2026-08-01', '2026-08-15');
        $old->fatigueFactor = 0.9;
        $new->fatigueFactor = 0.8;

        $result = $this->resolver->resolve($old, $new);

        // STAGES from endDate + fatigueFactor, WEATHER + CALENDAR from startDate
        $this->assertContains(ComputationName::STAGES, $result);
        $this->assertContains(ComputationName::WEATHER, $result);
        $this->assertContains(ComputationName::CALENDAR, $result);

        // No duplicates
        $values = array_map(static fn (ComputationName $c): string => $c->value, $result);
        $this->assertSame($values, array_unique($values));
    }

    #[Test]
    public function nullDatesComparedCorrectly(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/123', '2026-07-01');

        $result = $this->resolver->resolve($old, $new);

        $this->assertContains(ComputationName::WEATHER, $result);
        $this->assertContains(ComputationName::CALENDAR, $result);
    }

    #[Test]
    public function ebikeModeChangeReturnsTerrain(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/123');
        $old->ebikeMode = false;
        $new->ebikeMode = true;

        $result = $this->resolver->resolve($old, $new);

        $this->assertContains(ComputationName::TERRAIN, $result);
        $this->assertNotContains(ComputationName::STAGES, $result);
    }

    #[Test]
    public function maxDistancePerDayChangeReturnsStages(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/123');
        $old->maxDistancePerDay = 80.0;
        $new->maxDistancePerDay = 50.0;

        $result = $this->resolver->resolve($old, $new);

        $this->assertContains(ComputationName::STAGES, $result);
    }

    #[Test]
    public function bothNullDatesAreEqual(): void
    {
        $old = $this->createRequest('https://www.komoot.com/tour/123');
        $new = $this->createRequest('https://www.komoot.com/tour/123');

        // No date changes when both are null
        $result = $this->resolver->resolve($old, $new);

        $this->assertSame([], $result);
    }

    private function createRequest(string $sourceUrl, ?string $startDate = null, ?string $endDate = null): TripRequest
    {
        $request = new TripRequest();
        $request->sourceUrl = $sourceUrl;
        if (null !== $startDate) {
            $request->startDate = new \DateTimeImmutable($startDate);
        }

        if (null !== $endDate) {
            $request->endDate = new \DateTimeImmutable($endDate);
        }

        return $request;
    }
}
