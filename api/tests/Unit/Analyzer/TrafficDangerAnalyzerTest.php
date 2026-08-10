<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\Rules\TrafficDangerAnalyzer;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TrafficDangerAnalyzerTest extends TestCase
{
    use AlertMessageTestTrait;

    private TrafficDangerAnalyzer $analyzer;

    #[\Override]
    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new TrafficDangerAnalyzer($translator, $this->createDistanceFormatter());
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
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alerts[0]->action->kind);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->action->payload['lat'], 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->action->payload['lon'], 0.001);
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
    public function nudgeAlertForSecondaryWithoutMaxspeed(): void
    {
        $stage = $this->createStage();

        // A missing maxspeed tag is missing data, not a danger: NUDGE, never WARNING.
        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->lon, 0.001);
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alerts[0]->action->kind);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->action->payload['lat'], 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->action->payload['lon'], 0.001);
    }

    #[Test]
    public function nudgeAlertForSecondaryWithUnreadableMaxspeed(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'secondary', 'maxspeed' => 'walk', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
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
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alerts[0]->action->kind);
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
                ['highway' => 'secondary', 'maxspeed' => '90', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta(45.5, $alerts[0]->lat, 0.001);
        $this->assertEqualsWithDelta(5.5, $alerts[0]->lon, 0.001);
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alerts[0]->action->kind);
    }

    #[Test]
    public function separateAlertsPerSeverity(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'lat' => 45.5, 'lon' => 5.5, 'length' => 600.0],
                ['highway' => 'secondary', 'maxspeed' => '90', 'lat' => 45.6, 'lon' => 5.6, 'length' => 700.0],
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
    public function navigateActionCarriesTheConcernedSegmentsPerSeverity(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'primary', 'length' => 600.0, 'geometry' => [[[45.5, 5.5], [45.6, 5.6]]]],
                ['highway' => 'primary', 'length' => 700.0, 'geometry' => [[[45.7, 5.7], [45.8, 5.8]]]],
                ['highway' => 'secondary', 'maxspeed' => '90', 'length' => 800.0, 'geometry' => [[[46.0, 6.0], [46.1, 6.1]]]],
            ],
        ]);

        $this->assertCount(2, $alerts);
        // Critical bucket: both primary segments.
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(
            [[[45.5, 5.5], [45.6, 5.6]], [[45.7, 5.7], [45.8, 5.8]]],
            $alerts[0]->action->payload['segments'],
        );
        // Warning bucket: only the fast secondary segment.
        $this->assertNotNull($alerts[1]->action);
        $this->assertSame([[[46.0, 6.0], [46.1, 6.1]]], $alerts[1]->action->payload['segments']);
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
    public function noAlertForRestDay(): void
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 0.0,
            elevation: 0.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.0, 5.0),
            isRestDay: true,
        );

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [['highway' => 'primary', 'length' => 3000.0]],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, TrafficDangerAnalyzer::getPriority());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function renderedMessageProvider(): iterable
    {
        yield 'french' => ['fr', '2 segment(s) sur route principale sans piste cyclable (12,4 km au total).'];
        yield 'english' => ['en', '2 segment(s) on main road without bike lane detected (12.4 km in total).'];
    }

    #[DataProvider('renderedMessageProvider')]
    #[Test]
    public function renderedMessageSumsLengthsInKilometres(string $locale, string $expected): void
    {
        $analyzer = new TrafficDangerAnalyzer($this->createAlertTranslator(), $this->createDistanceFormatter());

        $alerts = $analyzer->analyze($this->createStage(), [
            'locale' => $locale,
            'osmWays' => [
                ['highway' => 'primary', 'length' => 6_200.0],
                ['highway' => 'trunk', 'length' => 6_200.0],
            ],
        ]);

        $this->assertSame($expected, $alerts[0]->message);
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
