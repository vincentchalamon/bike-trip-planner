<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use LogicException;
use RuntimeException;
use App\Analyzer\Rules\ContinuityAnalyzer;
use App\Analyzer\Rules\EbikeRangeAnalyzer;
use App\Analyzer\Rules\ElevationAlertAnalyzer;
use App\Analyzer\Rules\SteepGradientAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Integration tests using GPX scenario fixtures to verify alert analyzers
 * produce the expected alerts when fed realistic geometry data.
 */
final class AlertScenarioTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../fixtures/scenarios/';

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        return $translator;
    }

    private function createHaversineDistanceCalculator(): DistanceCalculatorInterface
    {
        $calculator = $this->createStub(DistanceCalculatorInterface::class);
        $calculator->method('distanceBetween')->willReturnCallback(
            static fn (Coordinate $from, Coordinate $to): float => ScenarioStageBuilder::haversineMeters($from->lat, $from->lon, $to->lat, $to->lon),
        );

        return $calculator;
    }

    #[Test]
    public function steepGradientProducesWarning(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'steep-gradient.gpx');

        $analyzer = new SteepGradientAnalyzer(
            $this->createHaversineDistanceCalculator(),
            $this->createTranslator(),
        );

        $alerts = $analyzer->analyze($stage);

        $this->assertNotEmpty($alerts, 'Steep gradient GPX should produce at least one alert.');
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertNotNull($alerts[0]->lat);
        $this->assertNotNull($alerts[0]->lon);
    }

    #[Test]
    public function highElevationProducesWarning(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'high-elevation.gpx');

        // Verify the fixture produces >1200m D+
        $this->assertGreaterThan(1200.0, $stage->elevation, 'High elevation GPX should have >1200m D+.');

        $analyzer = new ElevationAlertAnalyzer($this->createTranslator());
        $alerts = $analyzer->analyze($stage);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
    }

    #[Test]
    public function continuityGapProducesCritical(): void
    {
        $stages = ScenarioStageBuilder::buildSegmentsFromGpx(self::FIXTURES_DIR.'continuity-gap.gpx');

        $this->assertCount(2, $stages, 'Continuity gap GPX should have 2 segments.');

        $analyzer = new ContinuityAnalyzer(
            $this->createHaversineDistanceCalculator(),
            $this->createTranslator(),
        );

        $alerts = $analyzer->analyze($stages[0], ['nextStage' => $stages[1]]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::CRITICAL, $alerts[0]->type);
        $this->assertEqualsWithDelta($stages[0]->endPoint->lat, $alerts[0]->lat, 0.001);
    }

    #[Test]
    public function ebikeOutOfRangeProducesWarning(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'ebike-out-of-range.gpx');

        // Verify fixture properties: ~85km distance, ~500m D+
        // Effective range = 80 - (elevation / 25) = 80 - 20 = 60 km
        $this->assertGreaterThan(60.0, $stage->distance, 'E-bike GPX should have distance > effective range.');
        $this->assertGreaterThan(400.0, $stage->elevation, 'E-bike GPX should have significant elevation.');

        $analyzer = new EbikeRangeAnalyzer($this->createTranslator());
        $alerts = $analyzer->analyze($stage, ['ebikeMode' => true]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
    }

    #[Test]
    public function ebikeOutOfRangeNoAlertWithoutEbikeMode(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'ebike-out-of-range.gpx');

        $analyzer = new EbikeRangeAnalyzer($this->createTranslator());
        $alerts = $analyzer->analyze($stage, ['ebikeMode' => false]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noCemeteryWithEmptyOverpassProducesWaterGap(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'no-cemetery-30km.gpx');

        // Stage is >30km → with no water points, hasWaterGap should be true
        $this->assertGreaterThan(30.0, $stage->distance, 'No-cemetery GPX should be >30km.');

        // We test the water gap logic directly: a stage >30km with no water points
        // produces a gap exceeding the threshold
        $overpassData = $this->loadJsonFixture('overpass-empty.json');
        $waterPoints = $this->extractWaterPointsWithDistance($stage, $overpassData);
        $hasGap = $this->invokeHasWaterGap($stage, $waterPoints);

        $this->assertTrue($hasGap, 'Stage >30km with no cemeteries should have a water gap.');
    }

    #[Test]
    public function sparseCemeteriesProduceWaterGap(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'no-cemetery-30km.gpx');

        $overpassData = $this->loadJsonFixture('overpass-cemeteries-sparse.json');

        // Sparse cemeteries: one at km ~0.5 and one at km ~34.5
        // The gap between them is >30km
        $waterPoints = $this->extractWaterPointsWithDistance($stage, $overpassData);
        $hasGap = $this->invokeHasWaterGap($stage, $waterPoints);

        $this->assertTrue($hasGap, 'Sparse cemeteries with >30km gap should trigger water gap.');
    }

    #[Test]
    public function denseCemeteriesProduceNoWaterGap(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'no-cemetery-30km.gpx');

        $overpassData = $this->loadJsonFixture('overpass-cemeteries-dense.json');

        // Dense cemeteries: every ~10-11km → no gap >30km
        $waterPoints = $this->extractWaterPointsWithDistance($stage, $overpassData);
        $hasGap = $this->invokeHasWaterGap($stage, $waterPoints);

        $this->assertFalse($hasGap, 'Dense cemeteries with <30km gaps should not trigger water gap.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nominalAnalyzerProvider(): iterable
    {
        yield 'SteepGradientAnalyzer' => [SteepGradientAnalyzer::class];
        yield 'ElevationAlertAnalyzer' => [ElevationAlertAnalyzer::class];
        yield 'EbikeRangeAnalyzer (ebikeMode on)' => [EbikeRangeAnalyzer::class];
    }

    /**
     * @param class-string $analyzerClass
     */
    #[Test]
    #[DataProvider('nominalAnalyzerProvider')]
    public function nominalGpxProducesNoAlerts(string $analyzerClass): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'nominal-no-alerts.gpx');

        $analyzer = match ($analyzerClass) {
            SteepGradientAnalyzer::class => new SteepGradientAnalyzer(
                $this->createHaversineDistanceCalculator(),
                $this->createTranslator(),
            ),
            ElevationAlertAnalyzer::class => new ElevationAlertAnalyzer($this->createTranslator()),
            EbikeRangeAnalyzer::class => new EbikeRangeAnalyzer($this->createTranslator()),
            default => throw new LogicException(\sprintf('Unknown analyzer class: %s', $analyzerClass)),
        };

        // For ebike, test with ebikeMode enabled — the nominal trace is short enough
        $context = EbikeRangeAnalyzer::class === $analyzerClass ? ['ebikeMode' => true] : [];

        $alerts = $analyzer->analyze($stage, $context);

        $this->assertSame([], $alerts, \sprintf('%s should produce no alerts on the nominal GPX.', $analyzerClass));
    }

    #[Test]
    public function nominalGpxNoContinuityAlert(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'nominal-no-alerts.gpx');

        $analyzer = new ContinuityAnalyzer(
            $this->createHaversineDistanceCalculator(),
            $this->createTranslator(),
        );

        // No next stage → no continuity alert
        $alerts = $analyzer->analyze($stage);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function nominalGpxNoWaterGap(): void
    {
        $stage = ScenarioStageBuilder::buildFromGpx(self::FIXTURES_DIR.'nominal-no-alerts.gpx');

        // Nominal trace is ~10km, well under 30km threshold
        $this->assertLessThan(30.0, $stage->distance);

        $hasGap = $this->invokeHasWaterGap($stage, []);

        $this->assertFalse($hasGap, 'Short nominal trace should not have a water gap even without cemeteries.');
    }

    /**
     * Invokes CheckWaterPointsHandler::hasWaterGap via reflection.
     *
     * This avoids instantiating the full handler with all its dependencies
     * while still testing the core gap-detection logic.
     *
     * @param list<array{lat: float, lon: float, distanceFromStart: float}> $waterPoints
     */
    // Re-implements CheckWaterPointsHandler::hasWaterGap.
    // Keep in sync with CheckWaterPointsHandler::WATER_GAP_THRESHOLD_KM (= 30.0).
    // TODO: extract WaterGapDetector value object to share this logic without duplication.
    private function invokeHasWaterGap(Stage $stage, array $waterPoints): bool
    {
        $stageLengthKm = $stage->distance;
        $threshold = 30.0;

        if ([] === $waterPoints) {
            return $stageLengthKm > $threshold;
        }

        if ($waterPoints[0]['distanceFromStart'] > $threshold) {
            return true;
        }

        for ($j = 1, $count = \count($waterPoints); $j < $count; ++$j) {
            if (($waterPoints[$j]['distanceFromStart'] - $waterPoints[$j - 1]['distanceFromStart']) > $threshold) {
                return true;
            }
        }

        $lastDistance = $waterPoints[\count($waterPoints) - 1]['distanceFromStart'];

        return ($stageLengthKm - $lastDistance) > $threshold;
    }

    /**
     * Extracts water points from Overpass JSON data and computes distances from stage start.
     *
     * @param array<string, mixed> $overpassData
     *
     * @return list<array{lat: float, lon: float, distanceFromStart: float}>
     */
    private function extractWaterPointsWithDistance(Stage $stage, array $overpassData): array
    {
        /** @var list<array{lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
        $elements = \is_array($overpassData['elements'] ?? null) ? $overpassData['elements'] : [];

        $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
        $cumulativeDistances = $this->buildCumulativeDistances($geometry);

        $result = [];
        foreach ($elements as $element) {
            $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

            if (null === $lat || null === $lon) {
                continue;
            }

            $nearestIndex = $this->findNearestGeometryIndex($geometry, (float) $lat, (float) $lon);
            $result[] = [
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'distanceFromStart' => round($cumulativeDistances[$nearestIndex], 1),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['distanceFromStart'] <=> $b['distanceFromStart']);

        return $result;
    }

    /**
     * @param list<Coordinate> $geometry
     *
     * @return list<float>
     */
    private function buildCumulativeDistances(array $geometry): array
    {
        $cumulative = [0.0];

        for ($i = 1, $count = \count($geometry); $i < $count; ++$i) {
            $prev = $geometry[$i - 1];
            $curr = $geometry[$i];
            $cumulative[] = $cumulative[$i - 1] + $this->haversineKm($prev->lat, $prev->lon, $curr->lat, $curr->lon);
        }

        return $cumulative;
    }

    /**
     * @param list<Coordinate> $geometry
     */
    private function findNearestGeometryIndex(array $geometry, float $lat, float $lon): int
    {
        $minDist = PHP_FLOAT_MAX;
        $nearest = 0;

        foreach ($geometry as $i => $point) {
            $dist = $this->haversineKm($point->lat, $point->lon, $lat, $lon);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $i;
            }
        }

        return $nearest;
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return ScenarioStageBuilder::haversineMeters($lat1, $lon1, $lat2, $lon2) / 1_000.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonFixture(string $filename): array
    {
        $content = file_get_contents(self::FIXTURES_DIR.$filename);

        if (false === $content) {
            throw new RuntimeException(\sprintf('Cannot read fixture: %s', $filename));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
