<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\Rules\EbikeRangeAnalyzer;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use App\Osm\ChargingStationRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EbikeRangeAnalyzerTest extends TestCase
{
    private EbikeRangeAnalyzer $analyzer;

    #[\Override]
    protected function setUp(): void
    {
        // Default analyzer with no charger in range: exercises the distance-reduction fallback.
        $this->analyzer = $this->analyzerWithNearestCharger(null);
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

        // No charger in range: falls back to the distance-reduction auto-fix action.
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
    public function navigateActionPointsToNearestChargerInCorridor(): void
    {
        // The repository (corridor query) resolves the nearest charger; the analyzer
        // points the cyclist to it.
        $analyzer = $this->analyzerWithNearestCharger(
            ['name' => 'Near Charger', 'category' => 'charging_station', 'lat' => 48.001, 'lon' => 2.0],
        );

        $stage = $this->createStage(distance: 90.0, elevation: 0.0, geometry: [
            new Coordinate(48.0, 2.0),
            new Coordinate(48.002, 2.0),
        ]);

        $alerts = $analyzer->analyze($stage, ['ebikeMode' => true]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertSame(48.001, $alerts[0]->lat);
        $this->assertSame(2.0, $alerts[0]->lon);

        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alerts[0]->action->kind);
        // Navigate target is the nearest charger; the maxDistance hint is preserved.
        $this->assertSame(48.001, $alerts[0]->action->payload['lat']);
        $this->assertSame(2.0, $alerts[0]->action->payload['lon']);
        $this->assertSame(80.0, $alerts[0]->action->payload['maxDistance']);
    }

    #[Test]
    public function usesLocaleFromContext(): void
    {
        $translationKeys = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $params = [], ?string $domain = null, ?string $locale = null) use (&$translationKeys): string {
                $translationKeys[] = [$id, $domain, $locale];

                return $id;
            }
        );

        $analyzer = new EbikeRangeAnalyzer($translator, $this->chargingStationRepository(null));
        $stage = $this->createStage(distance: 90.0, elevation: 0.0);

        $alerts = $analyzer->analyze($stage, ['ebikeMode' => true, 'locale' => 'fr']);

        $this->assertCount(1, $alerts);
        $this->assertContains(['alert.ebike_range.warning', 'alerts', 'fr'], $translationKeys);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, EbikeRangeAnalyzer::getPriority());
    }

    /**
     * @param array{name: ?string, category: string, lat: float, lon: float}|null $charger
     */
    private function analyzerWithNearestCharger(?array $charger): EbikeRangeAnalyzer
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        return new EbikeRangeAnalyzer($translator, $this->chargingStationRepository($charger));
    }

    /**
     * @param array{name: ?string, category: string, lat: float, lon: float}|null $charger
     */
    private function chargingStationRepository(?array $charger): ChargingStationRepositoryInterface
    {
        $repository = $this->createStub(ChargingStationRepositoryInterface::class);
        $repository->method('findNearestInCorridor')->willReturnCallback(
            static function (array $route, int $radiusMeters) use ($charger): ?array {
                self::assertSame(2000, $radiusMeters, 'findNearestInCorridor must use the 2 km charging-station corridor');

                return $charger;
            },
        );

        return $repository;
    }

    /**
     * @param list<Coordinate> $geometry
     */
    private function createStage(float $distance, float $elevation, array $geometry = []): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: $distance,
            elevation: $elevation,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
            geometry: $geometry,
        );
    }
}
