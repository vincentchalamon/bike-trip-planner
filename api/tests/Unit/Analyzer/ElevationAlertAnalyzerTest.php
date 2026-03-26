<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use Override;
use App\Analyzer\Rules\ElevationAlertAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ElevationAlertAnalyzerTest extends TestCase
{
    private ElevationAlertAnalyzer $analyzer;

    #[Override]
    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new ElevationAlertAnalyzer($translator);
    }

    #[Test]
    public function noAlertBelowThreshold(): void
    {
        $stage = $this->createStage(800.0);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertAtExactThreshold(): void
    {
        $stage = $this->createStage(1200.0);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function warningAboveThreshold(): void
    {
        $stage = $this->createStage(1500.0);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta($stage->startPoint->lat, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta($stage->startPoint->lon, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function warningJustAboveThreshold(): void
    {
        $stage = $this->createStage(1200.1);

        $alerts = $this->analyzer->analyze($stage);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
    }

    #[Test]
    public function usesLocaleFromContext(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')->with(
            'alert.elevation.warning',
            $this->anything(),
            'alerts',
            'fr',
        )->willReturn('Alerte élévation');

        $analyzer = new ElevationAlertAnalyzer($translator);
        $stage = $this->createStage(1500.0);

        $alerts = $analyzer->analyze($stage, ['locale' => 'fr']);

        $this->assertCount(1, $alerts);
    }

    #[Test]
    public function defaultsToEnglishLocale(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')->with(
            'alert.elevation.warning',
            $this->anything(),
            'alerts',
            'en',
        )->willReturn('Elevation warning');

        $analyzer = new ElevationAlertAnalyzer($translator);
        $stage = $this->createStage(1500.0);

        $alerts = $analyzer->analyze($stage);

        $this->assertCount(1, $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(10, ElevationAlertAnalyzer::getPriority());
    }

    private function createStage(float $elevation): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: $elevation,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
        );
    }
}
