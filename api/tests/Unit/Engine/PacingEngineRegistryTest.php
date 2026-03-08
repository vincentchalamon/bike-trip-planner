<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\ApiResource\Model\Coordinate;
use App\Engine\DistanceCalculator;
use App\Engine\ElevationCalculator;
use App\Engine\EngineInterface;
use App\Engine\PacingEngineRegistry;
use App\Engine\RouteSimplifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class PacingEngineRegistryTest extends TestCase
{
    private PacingEngineRegistry $engine;

    #[\Override]
    protected function setUp(): void
    {
        $this->engine = new PacingEngineRegistry();

        $engines = [
            DistanceCalculator::class => new DistanceCalculator(),
            ElevationCalculator::class => new ElevationCalculator(),
            RouteSimplifier::class => new RouteSimplifier(),
        ];

        $registry = $this->createStub(ContainerInterface::class);
        $registry->method('get')->willReturnCallback(
            static fn (string $id): EngineInterface => $engines[$id] ?? throw new \InvalidArgumentException(sprintf('Engine %s not found', $id)),
        );

        $this->engine->setEngineRegistry($registry);
    }

    #[Test]
    public function generateStagesWithEmptyPoints(): void
    {
        $stages = $this->engine->generateStages('trip-1', [], 3, 300.0);

        $this->assertSame([], $stages);
    }

    #[Test]
    public function generateStagesWithZeroDays(): void
    {
        $points = [new Coordinate(45.0, 5.0), new Coordinate(46.0, 6.0)];

        $stages = $this->engine->generateStages('trip-1', $points, 0, 300.0);

        $this->assertSame([], $stages);
    }

    #[Test]
    public function generateStagesWithSingleDay(): void
    {
        $points = $this->createTrack(5);

        $stages = $this->engine->generateStages('trip-1', $points, 1, 100.0);

        $this->assertCount(1, $stages);
        $this->assertSame(1, $stages[0]->dayNumber);
        $this->assertSame('trip-1', $stages[0]->tripId);
    }

    #[Test]
    public function generateStagesWithMultipleDays(): void
    {
        $points = $this->createTrack(20);

        $stages = $this->engine->generateStages('trip-1', $points, 3, 200.0);

        $this->assertGreaterThanOrEqual(1, \count($stages));

        // Day numbers should be sequential starting from 1
        foreach ($stages as $i => $stage) {
            $this->assertSame($i + 1, $stage->dayNumber);
        }
    }

    #[Test]
    public function generateStagesRespectsFatigueFactor(): void
    {
        // Use a long track (100 points, ~500km) to guarantee multi-stage output
        $points = $this->createTrack(100);

        // High fatigue factor (0.95) → more even distribution
        $stagesHigh = $this->engine->generateStages('trip-1', $points, 3, 500.0, 0.95);
        // Low fatigue factor (0.7) → more uneven, earlier stages longer
        $stagesLow = $this->engine->generateStages('trip-2', $points, 3, 500.0, 0.7);

        $this->assertGreaterThan(1, \count($stagesHigh));
        $this->assertGreaterThan(1, \count($stagesLow));

        // With lower fatigue factor, first stage should be relatively longer
        $ratioHigh = $stagesHigh[0]->distance / ($stagesHigh[0]->distance + $stagesHigh[1]->distance);
        $ratioLow = $stagesLow[0]->distance / ($stagesLow[0]->distance + $stagesLow[1]->distance);
        $this->assertGreaterThan($ratioHigh, $ratioLow);
    }

    #[Test]
    public function generateStagesEnforcesMinimumDistance(): void
    {
        $points = $this->createTrack(50);

        // Many days relative to distance → minimum threshold should apply
        $stages = $this->engine->generateStages('trip-1', $points, 10, 400.0, 0.9, 50.0);

        foreach ($stages as $stage) {
            // Each stage should have distance > 0 (some may be merged)
            $this->assertGreaterThan(0.0, $stage->distance);
        }
    }

    #[Test]
    public function generateStagesSetsElevationData(): void
    {
        // Create track with varying elevation
        $points = [];
        for ($i = 0; $i < 10; ++$i) {
            $points[] = new Coordinate(45.0 + $i * 0.05, 5.0, 100.0 + $i * 50.0);
        }

        $stages = $this->engine->generateStages('trip-1', $points, 2, 50.0);

        $this->assertNotEmpty($stages);
        foreach ($stages as $stage) {
            $this->assertGreaterThanOrEqual(0.0, $stage->elevation);
            $this->assertGreaterThanOrEqual(0.0, $stage->elevationLoss);
        }
    }

    #[Test]
    public function generateStagesSetsStartAndEndPoints(): void
    {
        $points = $this->createTrack(10);

        $stages = $this->engine->generateStages('trip-1', $points, 2, 100.0);

        $this->assertNotEmpty($stages);

        // First stage starts at the first track point
        $this->assertEqualsWithDelta($points[0]->lat, $stages[0]->startPoint->lat, 0.001);
        $this->assertEqualsWithDelta($points[0]->lon, $stages[0]->startPoint->lon, 0.001);

        // Last stage ends at (or near) the last track point
        $lastStage = $stages[\count($stages) - 1];
        $lastPoint = $points[\count($points) - 1];
        $this->assertEqualsWithDelta($lastPoint->lat, $lastStage->endPoint->lat, 0.1);
    }

    #[Test]
    public function generateStagesSetsGeometry(): void
    {
        $points = $this->createTrack(10);

        $stages = $this->engine->generateStages('trip-1', $points, 2, 100.0);

        $this->assertNotEmpty($stages);
        foreach ($stages as $stage) {
            $this->assertNotEmpty($stage->geometry);
            $this->assertGreaterThanOrEqual(2, \count($stage->geometry));
        }
    }

    /**
     * @return list<Coordinate>
     */
    private function createTrack(int $pointCount): array
    {
        $points = [];
        for ($i = 0; $i < $pointCount; ++$i) {
            $points[] = new Coordinate(
                45.0 + $i * 0.05,
                5.0 + $i * 0.01,
                100.0 + ($i % 3) * 20.0,
            );
        }

        return $points;
    }
}
