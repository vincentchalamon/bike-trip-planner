<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\Rules\ContinuityAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculator;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContinuityAnalyzerTest extends TestCase
{
    private ContinuityAnalyzer $analyzer;

    #[\Override]
    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new ContinuityAnalyzer(new DistanceCalculator(), $translator);
    }

    #[Test]
    public function noAlertWithoutNextStage(): void
    {
        $stage = $this->createStage(45.0, 5.0, 45.1, 5.1);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertWhenStagesAreContinuous(): void
    {
        $stage = $this->createStage(45.0, 5.0, 45.1, 5.1);
        $nextStage = $this->createStage(45.1, 5.1, 45.2, 5.2);

        $alerts = $this->analyzer->analyze($stage, ['nextStage' => $nextStage]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function warningForSmallGap(): void
    {
        // End of stage 1 at (45.1, 5.1), start of stage 2 at (45.102, 5.1)
        // Gap ≈ 222 meters (between 100m and 500m)
        $stage = $this->createStage(45.0, 5.0, 45.1, 5.1);
        $nextStage = $this->createStage(45.102, 5.1, 45.2, 5.2);

        $alerts = $this->analyzer->analyze($stage, ['nextStage' => $nextStage]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta($stage->endPoint->lat, $alerts[0]->lat, 0.001);
    }

    #[Test]
    public function criticalForLargeGap(): void
    {
        // End of stage 1 at (45.1, 5.1), start of stage 2 at (45.2, 5.2)
        // Gap ≈ 14 km (> 500m critical threshold)
        $stage = $this->createStage(45.0, 5.0, 45.1, 5.1);
        $nextStage = $this->createStage(45.2, 5.2, 45.3, 5.3);

        $alerts = $this->analyzer->analyze($stage, ['nextStage' => $nextStage]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
    }

    #[Test]
    public function noAlertForGapBelowWarningThreshold(): void
    {
        // Very small gap, well below 100m
        $stage = $this->createStage(45.0, 5.0, 45.1, 5.1);
        $nextStage = $this->createStage(45.10005, 5.10005, 45.2, 5.2);

        $alerts = $this->analyzer->analyze($stage, ['nextStage' => $nextStage]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(5, ContinuityAnalyzer::getPriority());
    }

    private function createStage(float $startLat, float $startLon, float $endLat, float $endLon): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate($startLat, $startLon),
            endPoint: new Coordinate($endLat, $endLon),
        );
    }
}
