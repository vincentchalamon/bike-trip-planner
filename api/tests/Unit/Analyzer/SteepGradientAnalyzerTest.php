<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use Override;
use App\Analyzer\Rules\SteepGradientAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SteepGradientAnalyzerTest extends TestCase
{
    private SteepGradientAnalyzer $analyzer;

    #[Override]
    protected function setUp(): void
    {
        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('distanceBetween')->willReturnCallback(
            static function (Coordinate $from, Coordinate $to): float {
                // Haversine approximation for test purposes
                $latDiff = deg2rad($to->lat - $from->lat);
                $lonDiff = deg2rad($to->lon - $from->lon);
                $a = sin($latDiff / 2) ** 2
                    + cos(deg2rad($from->lat)) * cos(deg2rad($to->lat)) * sin($lonDiff / 2) ** 2;

                return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
            },
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new SteepGradientAnalyzer($distanceCalculator, $translator);
    }

    #[Test]
    public function noAlertOnFlatTerrain(): void
    {
        $stage = $this->createStageWithGeometry([
            new Coordinate(45.0, 5.0, 200.0),
            new Coordinate(45.01, 5.0, 201.0),
            new Coordinate(45.02, 5.0, 202.0),
        ]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertOnEmptyGeometry(): void
    {
        $stage = $this->createStageWithGeometry([]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertOnSinglePoint(): void
    {
        $stage = $this->createStageWithGeometry([
            new Coordinate(45.0, 5.0, 200.0),
        ]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertWhenSteepButTooShort(): void
    {
        // Two points ~111m apart with 10m elevation gain (~9% gradient) — under 500m distance
        $stage = $this->createStageWithGeometry([
            new Coordinate(45.0, 5.0, 200.0),
            new Coordinate(45.001, 5.0, 210.0),
        ]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function warningOnSteepGradientOverMinDistance(): void
    {
        // Build a climb: ~10% gradient over ~550m (5 segments of ~111m each, 11m elevation gain each)
        $stage = $this->createStageWithGeometry([
            new Coordinate(45.0, 5.0, 200.0),
            new Coordinate(45.001, 5.0, 211.0),
            new Coordinate(45.002, 5.0, 222.0),
            new Coordinate(45.003, 5.0, 233.0),
            new Coordinate(45.004, 5.0, 244.0),
            new Coordinate(45.005, 5.0, 255.0),
        ]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta(45.0, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.0, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function multipleAlerts(): void
    {
        // First steep section, then flat, then second steep section
        $stage = $this->createStageWithGeometry([
            // Steep section 1
            new Coordinate(45.0, 5.0, 200.0),
            new Coordinate(45.001, 5.0, 211.0),
            new Coordinate(45.002, 5.0, 222.0),
            new Coordinate(45.003, 5.0, 233.0),
            new Coordinate(45.004, 5.0, 244.0),
            new Coordinate(45.005, 5.0, 255.0),
            // Flat section
            new Coordinate(45.006, 5.0, 255.0),
            new Coordinate(45.007, 5.0, 255.0),
            // Steep section 2
            new Coordinate(45.008, 5.0, 255.0),
            new Coordinate(45.009, 5.0, 266.0),
            new Coordinate(45.010, 5.0, 277.0),
            new Coordinate(45.011, 5.0, 288.0),
            new Coordinate(45.012, 5.0, 299.0),
            new Coordinate(45.013, 5.0, 310.0),
        ]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertCount(2, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertSame(AlertType::WARNING, $alerts[1]->type);
        // First alert at section 1 start
        $this->assertEqualsWithDelta(45.0, $alerts[0]->lat, 0.001);
        // Second alert at section 2 start
        $this->assertEqualsWithDelta(45.008, $alerts[1]->lat, 0.001);
    }

    #[Test]
    public function noAlertOnDescent(): void
    {
        // Steep descent (-10%) should not trigger
        $stage = $this->createStageWithGeometry([
            new Coordinate(45.0, 5.0, 300.0),
            new Coordinate(45.001, 5.0, 289.0),
            new Coordinate(45.002, 5.0, 278.0),
            new Coordinate(45.003, 5.0, 267.0),
            new Coordinate(45.004, 5.0, 256.0),
            new Coordinate(45.005, 5.0, 245.0),
        ]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function trailingSteepSectionIsFlushed(): void
    {
        // Steep section at the end of geometry (no flat after it)
        $stage = $this->createStageWithGeometry([
            new Coordinate(45.0, 5.0, 200.0),
            new Coordinate(45.001, 5.0, 200.0),
            new Coordinate(45.002, 5.0, 211.0),
            new Coordinate(45.003, 5.0, 222.0),
            new Coordinate(45.004, 5.0, 233.0),
            new Coordinate(45.005, 5.0, 244.0),
            new Coordinate(45.006, 5.0, 255.0),
        ]);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertCount(1, $alerts);
    }

    #[Test]
    public function usesLocaleFromContext(): void
    {
        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('distanceBetween')->willReturn(120.0);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')->with(
            'alert.steep_gradient.warning',
            $this->anything(),
            'alerts',
            'fr',
        )->willReturn('Montée raide');

        $analyzer = new SteepGradientAnalyzer($distanceCalculator, $translator);

        $stage = $this->createStageWithGeometry([
            new Coordinate(45.0, 5.0, 200.0),
            new Coordinate(45.001, 5.0, 210.0),
            new Coordinate(45.002, 5.0, 220.0),
            new Coordinate(45.003, 5.0, 230.0),
            new Coordinate(45.004, 5.0, 240.0),
            new Coordinate(45.005, 5.0, 250.0),
        ]);

        $alerts = $analyzer->analyze($stage, ['locale' => 'fr']);

        $this->assertCount(1, $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, SteepGradientAnalyzer::getPriority());
    }

    /**
     * @param list<Coordinate> $geometry
     */
    private function createStageWithGeometry(array $geometry): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
            geometry: $geometry,
        );
    }
}
