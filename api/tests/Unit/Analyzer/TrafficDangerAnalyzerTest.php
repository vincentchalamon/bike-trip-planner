<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use Override;
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

    #[Override]
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
                ['highway' => 'primary', 'cycleway' => 'lane', 'length' => 600.0],
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
                ['highway' => 'primary', 'cycleway:right' => 'track', 'length' => 600.0],
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
                ['highway' => 'secondary', 'cycleway:left' => 'lane', 'length' => 600.0],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForDangerousHighwayWithCyclewayBoth(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'cycleway:both' => 'track', 'length' => 600.0],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForDangerousHighwayWithBicycleDesignated(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'bicycle' => 'designated', 'length' => 600.0],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForDangerousHighwayWithBicycleUseSidepath(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'bicycle' => 'use_sidepath', 'length' => 600.0],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForShortSegment(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'length' => 499.0],
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
                ['highway' => 'primary', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function criticalAlertForTrunkWithoutCycleway(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'trunk', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
    }

    #[Test]
    public function warningAlertForSecondaryWithoutCycleway(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function nudgeAlertForSecondaryWithLowMaxspeed(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'maxspeed' => '50', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function nudgeAlertBelowFiftyKmh(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'maxspeed' => '30', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
    }

    #[Test]
    public function warningAlertForSecondaryAboveFiftyKmh(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'maxspeed' => '90', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
    }

    #[Test]
    public function separateAlertsPerSeverity(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
                ['highway' => 'secondary', 'lat' => 45.6, 'lon' => 5.6, 'length' => 700.0],
                ['highway' => 'secondary', 'maxspeed' => '30', 'lat' => 45.7, 'lon' => 5.7, 'length' => 800.0],
            ],
        ]);

        $this->assertCount(3, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
        $this->assertSame(AlertType::WARNING, $alerts[1]->type);
        $this->assertSame(AlertType::NUDGE, $alerts[2]->type);
    }

    #[Test]
    public function criticalAlertGroupsMultiplePrimarySegments(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
                ['highway' => 'primary', 'lat' => 45.7, 'lon' => 5.7, 'length' => 700.0],
            ],
        ]);

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
                ['highway' => 'primary', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertEqualsWithDelta($stage->startPoint->lat, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta($stage->startPoint->lon, $alerts[0]->lon, 0.001);
    }

    #[Test]
    public function parseMaxspeedNumericFormat(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'maxspeed' => '50', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
    }

    #[Test]
    public function parseMaxspeedWithUnitFormat(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'maxspeed' => '50 km/h', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
    }

    #[Test]
    public function parseMaxspeedCountryCodeFormat(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'maxspeed' => 'FR:50', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
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
