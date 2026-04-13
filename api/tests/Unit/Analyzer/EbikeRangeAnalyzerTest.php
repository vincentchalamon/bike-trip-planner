<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\Rules\EbikeRangeAnalyzer;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EbikeRangeAnalyzerTest extends TestCase
{
    private EbikeRangeAnalyzer $analyzer;

    #[\Override]
    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new EbikeRangeAnalyzer($translator);
    }

    #[Test]
    public function noAlertWhenEbikeModeDisabled(): void
    {
        $stage = $this->createStage(distance: 100.0, elevation: 0.0);

        $alerts = $this->analyzer->analyze($stage, ['ebikeMode' => false]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertWhenDistanceWithinRange(): void
    {
        $stage = $this->createStage(distance: 60.0, elevation: 0.0);

        $alerts = $this->analyzer->analyze($stage, ['ebikeMode' => true]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function warningWhenDistanceExceedsBaseRange(): void
    {
        $stage = $this->createStage(distance: 90.0, elevation: 0.0);

        $alerts = $this->analyzer->analyze($stage, ['ebikeMode' => true]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::AUTO_FIX, $alerts[0]->action->kind);
        $this->assertSame(80.0, $alerts[0]->action->payload['maxDistance']);
    }

    #[Test]
    public function warningWhenElevationReducesRange(): void
    {
        // effectiveRange = 80 - (1000 / 25) = 80 - 40 = 40 km
        $stage = $this->createStage(distance: 60.0, elevation: 1000.0);

        $alerts = $this->analyzer->analyze($stage, ['ebikeMode' => true]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::AUTO_FIX, $alerts[0]->action->kind);
        $this->assertSame(40.0, $alerts[0]->action->payload['maxDistance']);
    }

    #[Test]
    public function noAlertWhenDistanceWithinElevationAdjustedRange(): void
    {
        // effectiveRange = 80 - (500 / 25) = 80 - 20 = 60 km
        $stage = $this->createStage(distance: 50.0, elevation: 500.0);

        $alerts = $this->analyzer->analyze($stage, ['ebikeMode' => true]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function effectiveRangeClampedToZeroOnExtremeElevation(): void
    {
        // effectiveRange = max(0, 80 - (2500 / 25)) = max(0, -20) = 0 km
        $stage = $this->createStage(distance: 30.0, elevation: 2500.0);

        $alerts = $this->analyzer->analyze($stage, ['ebikeMode' => true]);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('"%range%":0', $alerts[0]->message);
    }

    #[Test]
    public function noAlertWhenEbikeModeAbsentFromContext(): void
    {
        $stage = $this->createStage(distance: 100.0, elevation: 0.0);

        $alerts = $this->analyzer->analyze($stage, []);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, EbikeRangeAnalyzer::getPriority());
    }

    private function createStage(float $distance, float $elevation): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: $distance,
            elevation: $elevation,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
        );
    }
}
