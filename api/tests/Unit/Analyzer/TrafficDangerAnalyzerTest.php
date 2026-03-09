<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\Rules\TrafficDangerAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TrafficDangerAnalyzerTest extends TestCase
{
    private TrafficDangerAnalyzer $analyzer;

    #[\Override]
    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new TrafficDangerAnalyzer($translator);
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
    public function noAlertForSafeHighways(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'tertiary'],
                ['highway' => 'residential'],
                ['highway' => 'cycleway'],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForDangerousHighwayWithCycleway(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'cycleway' => 'lane'],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForDangerousHighwayWithCyclewayRight(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'cycleway:right' => 'track'],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForDangerousHighwayWithCyclewayLeft(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'cycleway:left' => 'lane'],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function criticalAlertForPrimaryWithoutCycleway(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'lat' => 45.5, 'lon' => 5.5],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function criticalAlertForSecondaryWithoutCycleway(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary'],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
    }

    #[Test]
    public function singleAlertForMultipleDangerousSegments(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'lat' => 45.5, 'lon' => 5.5],
                ['highway' => 'secondary', 'lat' => 45.6, 'lon' => 5.6],
                ['highway' => 'primary', 'lat' => 45.7, 'lon' => 5.7],
            ],
        ]);

        // Returns single alert with count of all dangerous segments
        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
        // Uses first segment location
        $this->assertEqualsWithDelta(45.5, $alerts[0]->lat, 0.001);
    }

    #[Test]
    public function fallsBackToStageStartPointWhenNoCoords(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary'],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertEqualsWithDelta($stage->startPoint->lat, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta($stage->startPoint->lon, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, TrafficDangerAnalyzer::getPriority());
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
