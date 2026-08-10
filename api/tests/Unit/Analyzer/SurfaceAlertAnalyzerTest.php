<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\Rules\SurfaceAlertAnalyzer;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SurfaceAlertAnalyzerTest extends TestCase
{
    use AlertMessageTestTrait;

    private SurfaceAlertAnalyzer $analyzer;

    #[\Override]
    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->analyzer = new SurfaceAlertAnalyzer($translator, $this->createDistanceFormatter());
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
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alerts[0]->action->kind);
        $this->assertEqualsWithDelta($stage->startPoint->lat, $alerts[0]->action->payload['lat'], 0.001);
        $this->assertEqualsWithDelta($stage->startPoint->lon, $alerts[0]->action->payload['lon'], 0.001);
        // No geometry on the way → no highlight segment (issue #982).
        $this->assertSame([], $alerts[0]->action->payload['segments']);
    }

    #[Test]
    public function navigateActionCarriesTheConcernedRoughSegments(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'gravel', 'length' => 300.0, 'geometry' => [[[45.0, 5.0], [45.1, 5.1]]]],
                // Smooth way: its geometry must NOT be highlighted.
                ['surface' => 'asphalt', 'length' => 9000.0, 'geometry' => [[[45.5, 5.5], [45.6, 5.6]]]],
                ['surface' => 'dirt', 'length' => 300.0, 'geometry' => [[[45.2, 5.2], [45.3, 5.3]]]],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(
            [[[45.0, 5.0], [45.1, 5.1]], [[45.2, 5.2], [45.3, 5.3]]],
            $alerts[0]->action->payload['segments'],
        );
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
        yield 'earth' => ['earth'];
        yield 'clay' => ['clay'];
        yield 'rock' => ['rock'];
        yield 'stone' => ['stone'];
        yield 'woodchips' => ['woodchips'];
        yield 'wood' => ['wood'];
        yield 'metal' => ['metal'];
        // Paved but rough: the alert still fires, the wording just must not call them unpaved.
        yield 'sett' => ['sett'];
        yield 'cobblestone' => ['cobblestone'];
        yield 'unhewn_cobblestone' => ['unhewn_cobblestone'];
        yield 'paving_stones' => ['paving_stones'];
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
    public function detectsCompositeSurfaceValue(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'gravel;dirt', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('alert.surface.warning', $alerts[0]->message);
        // Both components are reported, not the raw composite string.
        // Both components are translated now (issue #862), so the stub echoes their keys.
        $this->assertStringContainsString('surface.gravel', $alerts[0]->message);
        $this->assertStringContainsString('surface.dirt', $alerts[0]->message);
    }

    #[Test]
    public function ignoresCompositeSurfaceValueWithoutRoughComponent(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'asphalt;concrete', 'length' => 600.0],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpavedTracktypeProvider(): iterable
    {
        yield 'grade3' => ['grade3'];
        yield 'grade4' => ['grade4'];
        yield 'grade5' => ['grade5'];
    }

    #[DataProvider('unpavedTracktypeProvider')]
    #[Test]
    public function detectsTracktypeWhenSurfaceIsAbsent(string $tracktype): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'track', 'tracktype' => $tracktype, 'length' => 600.0],
            ],
        ]);

        // Only the rough-surface warning: the missing-surface-data rule was dropped
        // as a tag-presence alert (issue #861).
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('alert.surface.warning', $alerts[0]->message);
        // The fallback signal is translated as well, so no raw tag expression leaks.
        $this->assertStringContainsString('surface.tracktype_'.$tracktype, $alerts[0]->message);
    }

    #[Test]
    public function ignoresMaintainedTracktypeWhenSurfaceIsAbsent(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['highway' => 'track', 'tracktype' => 'grade1', 'length' => 600.0],
            ],
        ]);

        // No alert at all: grade1 is a solid surface, and an undocumented `surface`
        // is no longer an alert of its own (issue #861).
        $this->assertSame([], $alerts);
    }

    #[Test]
    public function detectsSmoothnessWhenSurfaceIsAbsent(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['smoothness' => 'very_bad', 'length' => 600.0],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('alert.surface.warning', $alerts[0]->message);
        $this->assertStringContainsString('surface.smoothness_very_bad', $alerts[0]->message);
    }

    #[Test]
    public function explicitSurfaceWinsOverSmoothnessFallback(): void
    {
        $stage = $this->createStage();

        $alerts = $this->analyzer->analyze($stage, [
            'osmWays' => [
                ['surface' => 'asphalt', 'smoothness' => 'bad', 'length' => 5000.0],
                ['surface' => 'asphalt', 'tracktype' => 'grade5', 'length' => 5000.0],
            ],
        ]);

        $this->assertSame([], $alerts);
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
    public function noAlertWhenSurfaceTagIsMissingOnMostWays(): void
    {
        $stage = $this->createStage();

        // 100 % of ways without a surface tag: OSM completeness is not a terrain fact
        $osmWays = array_fill(0, 10, ['length' => 100.0]);

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertWhenSurfaceTagIsAnEmptyString(): void
    {
        $stage = $this->createStage();

        $osmWays = array_fill(0, 5, ['surface' => '', 'length' => 100.0]);

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function onlyTheUnpavedAlertIsEmittedAlongsideUntaggedWays(): void
    {
        $stage = $this->createStage();

        // 6 ways without surface + 4 gravel ways totaling 600 m (> 500 m threshold)
        $osmWays = array_fill(0, 6, ['length' => 100.0]);
        $osmWays = [...$osmWays, ...array_fill(0, 4, ['surface' => 'gravel', 'length' => 150.0])];

        $alerts = $this->analyzer->analyze($stage, ['osmWays' => $osmWays]);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('alert.surface.warning', $alerts[0]->message);
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
            'osmWays' => [['surface' => 'gravel', 'length' => 5000.0]],
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, SurfaceAlertAnalyzer::getPriority());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function renderedMessageProvider(): iterable
    {
        yield 'french' => ['fr', 'Revêtement difficile sur 43,9 km au total (gravier, terre). Vérifie si ton vélo est adapté.'];
        yield 'english' => ['en', 'Rough surface totalling 43.9 km (gravel, dirt). Check bike suitability.'];
    }

    #[DataProvider('renderedMessageProvider')]
    #[Test]
    public function renderedMessageUsesKilometresAndTranslatedSurfaces(string $locale, string $expected): void
    {
        $analyzer = new SurfaceAlertAnalyzer($this->createAlertTranslator(), $this->createDistanceFormatter());

        $alerts = $analyzer->analyze($this->createStage(), [
            'locale' => $locale,
            'osmWays' => [
                ['surface' => 'gravel', 'length' => 40_000.0],
                ['surface' => 'dirt', 'length' => 3_871.0],
            ],
        ]);

        $this->assertSame($expected, $alerts[0]->message);
    }

    #[DataProvider('unpavedSurfaceProvider')]
    #[Test]
    public function frenchMessageNeverLeaksARawSurfaceTag(string $surface): void
    {
        $analyzer = new SurfaceAlertAnalyzer($this->createAlertTranslator(), $this->createDistanceFormatter());

        $alerts = $analyzer->analyze($this->createStage(), [
            'locale' => 'fr',
            'osmWays' => [['surface' => $surface, 'length' => 600.0]],
        ]);

        $this->assertStringNotContainsString($surface, $alerts[0]->message);
        $this->assertStringNotContainsString(
            'revêtement non documenté',
            $alerts[0]->message,
            \sprintf('Surface "%s" has no "surface.%s" translation.', $surface, $surface),
        );
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
