<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use Override;
use App\Analyzer\Rules\SurfaceAlertAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SurfaceAlertAnalyzerTest extends TestCase
{
    private SurfaceAlertAnalyzer $analyzer;

    #[Override]
    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new SurfaceAlertAnalyzer($translator);
    }

    #[Test]
    public function noAlertWithoutOsmWays(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertWithEmptyOsmWays(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => []]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForPavedSurfaces(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'asphalt', 'length' => 5000.0],
                ['surface' => 'concrete', 'length' => 3000.0],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForUnpavedBelowThreshold(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'gravel', 'length' => 400.0],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function warningForUnpavedAboveThreshold(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'gravel', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta($stage->startPoint->lat, $alerts[0]->lat, 0.001);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpavedSurfaceProvider(): iterable
    {
        yield 'unpaved' => ['unpaved'];
        yield 'gravel' => ['gravel'];
        yield 'dirt' => ['dirt'];
        yield 'ground' => ['ground'];
        yield 'grass' => ['grass'];
        yield 'sand' => ['sand'];
        yield 'mud' => ['mud'];
        yield 'compacted' => ['compacted'];
        yield 'fine_gravel' => ['fine_gravel'];
        yield 'pebblestone' => ['pebblestone'];
    }

    #[DataProvider('unpavedSurfaceProvider')]
    #[Test]
    public function detectsAllUnpavedSurfaces(string $surface): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => $surface, 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
    }

    #[Test]
    public function accumulatesUnpavedLengthAcrossWays(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'gravel', 'length' => 300.0],
                ['surface' => 'dirt', 'length' => 300.0],
            ],
        ]);

        // 300 + 300 = 600 > 500 threshold
        $this->assertCount(1, $alerts);
    }

    #[Test]
    public function alertAtExactThreshold(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'gravel', 'length' => 500.0],
            ],
        ]);

        // 500 is not < 500, so the condition `$unpavedLength < threshold` is false → alert fires
        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
    }

    #[Test]
    public function handlesWaysWithoutLength(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'gravel'],
            ],
        ]);

        // Missing length defaults to 0.0, below threshold
        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noMissingDataAlertBelowThreshold(): void
    {
        $stage = $this->createStage();

        // 1 out of 10 ways missing surface = 10% < 30%
        $osmWays = array_fill(0, 9, ['surface' => 'asphalt', 'length' => 100.0]);
        $osmWays[] = ['length' => 100.0];

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function missingDataAlertAtExactThreshold(): void
    {
        $stage = $this->createStage();

        // 3 out of 10 ways missing surface = 30% → alert fires
        $osmWays = array_fill(0, 7, ['surface' => 'asphalt', 'length' => 100.0]);
        $osmWays = [...$osmWays, ...array_fill(0, 3, ['length' => 100.0])];

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertStringContainsString('alert.surface.missing_data', $alerts[0]->message);
    }

    #[Test]
    public function missingDataAlertAboveThreshold(): void
    {
        $stage = $this->createStage();

        // 5 out of 10 ways missing surface = 50% > 30%
        $osmWays = array_fill(0, 5, ['surface' => 'asphalt', 'length' => 100.0]);
        $osmWays = [...$osmWays, ...array_fill(0, 5, ['length' => 100.0])];

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertStringContainsString('alert.surface.missing_data', $alerts[0]->message);
        $this->assertStringContainsString('50', $alerts[0]->message);
    }

    #[Test]
    public function missingDataAlertTreatsEmptyStringAsMissing(): void
    {
        $stage = $this->createStage();

        // All ways have empty surface string = 100% > 30%
        $osmWays = array_fill(0, 5, ['surface' => '', 'length' => 100.0]);

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('alert.surface.missing_data', $alerts[0]->message);
    }

    #[Test]
    public function bothUnpavedAndMissingDataAlerts(): void
    {
        $stage = $this->createStage();

        // 6 ways without surface (60% > 30%) + 4 gravel ways totaling 600m (> 500m threshold)
        $osmWays = array_fill(0, 6, ['length' => 100.0]);
        $osmWays = [...$osmWays, ...array_fill(0, 4, ['surface' => 'gravel', 'length' => 150.0])];

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertCount(2, $alerts);
        $this->assertStringContainsString('alert.surface.warning', $alerts[0]->message);
        $this->assertStringContainsString('alert.surface.missing_data', $alerts[1]->message);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, SurfaceAlertAnalyzer::getPriority());
    }

    private function createStage(): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
        );
    }
}
