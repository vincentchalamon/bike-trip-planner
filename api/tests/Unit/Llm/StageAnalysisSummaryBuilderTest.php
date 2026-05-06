<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use App\Llm\StageAnalysisSummaryBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StageAnalysisSummaryBuilderTest extends TestCase
{
    private const string TRIP_ID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function buildsCoreNumericFieldsForAStage(): void
    {
        $stage = $this->makeStage(dayNumber: 3, distance: 72.4, elevation: 850.0, elevationLoss: 820.5);

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertSame(3, $summary['stage_number']);
        self::assertSame(72.4, $summary['distance_km']);
        self::assertSame(850, $summary['elevation_gain_m']);
        self::assertSame(821, $summary['elevation_loss_m']);
        self::assertFalse($summary['is_rest_day']);
        self::assertArrayNotHasKey('label', $summary);
    }

    #[Test]
    public function includesLabelWhenNotEmpty(): void
    {
        $stage = $this->makeStage();
        $stage->label = 'Cluny → Mâcon';

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertSame('Cluny → Mâcon', $summary['label']);
    }

    #[Test]
    public function includesWeatherWhenAvailable(): void
    {
        $stage = $this->makeStage();
        $stage->weather = new WeatherForecast(
            icon: 'rain',
            description: 'Light rain',
            tempMin: 8.4,
            tempMax: 18.7,
            windSpeed: 22.0,
            windDirection: 'NE',
            precipitationProbability: 80,
            humidity: 70,
            comfortIndex: 45,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_HEADWIND,
        );

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertIsArray($summary['weather']);
        self::assertSame(8.4, $summary['weather']['temp_min_c']);
        self::assertSame(18.7, $summary['weather']['temp_max_c']);
        self::assertSame(22.0, $summary['weather']['wind_kmh']);
        self::assertSame('NE', $summary['weather']['wind_dir']);
        self::assertSame(WeatherForecast::RELATIVE_WIND_HEADWIND, $summary['weather']['relative_wind']);
        self::assertSame(80, $summary['weather']['precip_probability']);
        self::assertSame(45, $summary['weather']['comfort_index']);
        self::assertSame('Light rain', $summary['weather']['description']);
    }

    #[Test]
    public function dropsWeatherSectionWhenAbsent(): void
    {
        $summary = (new StageAnalysisSummaryBuilder())->build($this->makeStage());

        self::assertArrayNotHasKey('weather', $summary);
    }

    #[Test]
    public function selectsMostSevereAlertsWhenOverflowing(): void
    {
        $stage = $this->makeStage();
        for ($i = 0; $i < 5; ++$i) {
            $stage->addAlert(new Alert(AlertType::NUDGE, "nudge-$i"));
        }
        for ($i = 0; $i < 5; ++$i) {
            $stage->addAlert(new Alert(AlertType::WARNING, "warning-$i"));
        }
        for ($i = 0; $i < 5; ++$i) {
            $stage->addAlert(new Alert(AlertType::CRITICAL, "critical-$i"));
        }

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertIsArray($summary['alerts']);
        self::assertCount(StageAnalysisSummaryBuilder::MAX_ALERTS, $summary['alerts']);

        $criticalCount = 0;
        foreach ($summary['alerts'] as $alertSummary) {
            if (AlertType::CRITICAL->value === $alertSummary['type']) {
                ++$criticalCount;
            }
        }
        self::assertSame(5, $criticalCount, 'All CRITICAL alerts must be retained before WARNING/NUDGE.');
    }

    #[Test]
    public function dropsAlertsSectionWhenEmpty(): void
    {
        $summary = (new StageAnalysisSummaryBuilder())->build($this->makeStage());

        self::assertArrayNotHasKey('alerts', $summary);
    }

    #[Test]
    public function countsWaterPointsFromPois(): void
    {
        $stage = $this->makeStage();
        $stage->addPoi(new PointOfInterest('Fontaine', 'drinking_water', 48.0, 2.0));
        $stage->addPoi(new PointOfInterest('Source', 'water', 48.1, 2.1));
        $stage->addPoi(new PointOfInterest('Boulangerie', 'bakery', 48.2, 2.2));

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertSame(2, $summary['water_points']);
    }

    #[Test]
    public function buildsResupplyListFromRelevantPois(): void
    {
        $stage = $this->makeStage();
        $stage->addPoi(new PointOfInterest('Boulangerie Dupont', 'bakery', 48.0, 2.0));
        $stage->addPoi(new PointOfInterest('Carrefour Express', 'supermarket', 48.1, 2.1));
        $stage->addPoi(new PointOfInterest('Église', 'cultural', 48.2, 2.2));

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertIsArray($summary['resupply']);
        self::assertCount(2, $summary['resupply']);
        self::assertSame('Boulangerie Dupont', $summary['resupply'][0]['name']);
        self::assertSame('bakery', $summary['resupply'][0]['type']);
    }

    #[Test]
    public function cappedResupplyListAtMaxItems(): void
    {
        $stage = $this->makeStage();
        for ($i = 0; $i < StageAnalysisSummaryBuilder::MAX_LIST_ITEMS + 4; ++$i) {
            $stage->addPoi(new PointOfInterest("Shop $i", 'shop', 48.0 + $i / 100, 2.0));
        }

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertCount(StageAnalysisSummaryBuilder::MAX_LIST_ITEMS, $summary['resupply']);
    }

    #[Test]
    public function exposesSelectedAccommodationFirstAndDeduplicates(): void
    {
        $stage = $this->makeStage();
        $selected = new Accommodation(
            name: 'Gîte du Moulin',
            type: 'guest_house',
            lat: 48.5,
            lon: 2.5,
            estimatedPriceMin: 60.0,
            estimatedPriceMax: 90.0,
            isExactPrice: false,
        );
        $stage->selectedAccommodation = $selected;
        $stage->addAccommodation($selected); // duplicate of selected
        $stage->addAccommodation(new Accommodation(
            name: 'Hotel des Voyageurs',
            type: 'hotel',
            lat: 48.6,
            lon: 2.6,
            estimatedPriceMin: 80.0,
            estimatedPriceMax: 120.0,
            isExactPrice: false,
        ));

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        self::assertIsArray($summary['accommodations']);
        self::assertCount(2, $summary['accommodations']);
        self::assertSame('Gîte du Moulin', $summary['accommodations'][0]['name']);
        self::assertSame('Hotel des Voyageurs', $summary['accommodations'][1]['name']);
    }

    #[Test]
    public function summaryStaysWithinTokenBudget(): void
    {
        $stage = $this->makeStage(dayNumber: 7, distance: 110.0, elevation: 1500.0, elevationLoss: 1450.0);
        $stage->label = 'Étape exigeante';
        $stage->weather = new WeatherForecast(
            icon: 'rain',
            description: 'Pluie modérée',
            tempMin: 10.0,
            tempMax: 19.0,
            windSpeed: 28.0,
            windDirection: 'NE',
            precipitationProbability: 80,
            humidity: 70,
            comfortIndex: 50,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_HEADWIND,
        );
        for ($i = 0; $i < StageAnalysisSummaryBuilder::MAX_ALERTS; ++$i) {
            $stage->addAlert(new Alert(AlertType::WARNING, "alert message $i with some context"));
        }
        for ($i = 0; $i < StageAnalysisSummaryBuilder::MAX_LIST_ITEMS; ++$i) {
            $stage->addPoi(new PointOfInterest("Boulangerie $i", 'bakery', 48.0, 2.0));
        }

        $summary = (new StageAnalysisSummaryBuilder())->build($stage);

        $encoded = json_encode($summary, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        // Heuristic: Llama tokenizer ~ 1 token per 4 characters of compact JSON.
        // Cap at ~1200 chars (~300 tokens) to stay in the optimal LLaMA-8B zone.
        self::assertLessThan(1500, \strlen($encoded), 'Summary exceeds the ~300-token budget — review limits.');
    }

    private function makeStage(int $dayNumber = 1, float $distance = 50.0, float $elevation = 500.0, float $elevationLoss = 480.0): Stage
    {
        return new Stage(
            tripId: self::TRIP_ID,
            dayNumber: $dayNumber,
            distance: $distance,
            elevation: $elevation,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
            elevationLoss: $elevationLoss,
        );
    }
}
