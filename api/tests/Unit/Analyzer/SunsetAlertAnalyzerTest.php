<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use Override;
use DateTimeImmutable;
use DateTimeZone;
use App\Analyzer\Rules\SunsetAlertAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SunsetAlertAnalyzerTest extends TestCase
{
    private TranslatorInterface $translator;

    private Stub&RiderTimeEstimatorInterface $riderTimeEstimator;

    private SunsetAlertAnalyzer $analyzer;

    #[Override]
    protected function setUp(): void
    {
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        $this->analyzer = new SunsetAlertAnalyzer(
            $this->riderTimeEstimator,
            $this->translator,
        );
    }

    #[Test]
    public function noAlertForRestDay(): void
    {
        $stage = $this->createStage(isRestDay: true);

        $alerts = $this->analyzer->analyze($stage, []);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertWhenArrivalBeforeTwilight(): void
    {
        // Paris (48.85°N, 2.35°E) on a summer day — sunset ~21:00, twilight end ~21:30
        // Stage arrives at 17:00 (well before sunset)
        $stage = $this->createStage();

        $this->riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(17.0);

        $alerts = $this->analyzer->analyze($stage, [
            'startDate' => new DateTimeImmutable('2024-07-15', new DateTimeZone('UTC')),
            'stageIndex' => 0,
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function warningWhenArrivalAfterTwilight(): void
    {
        // Paris (48.85°N, 2.35°E) on a winter day — sunset ~17:00, civil twilight end ~17:30
        // Stage arrives at 22:00 (after civil twilight)
        $stage = $this->createStage(lat: 48.85, lon: 2.35);

        $this->riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(22.0);

        $alerts = $this->analyzer->analyze($stage, [
            'startDate' => new DateTimeImmutable('2024-12-15', new DateTimeZone('UTC')),
            'stageIndex' => 0,
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta(48.85, $alerts[0]->lat ?? 0.0, 0.01);
        $this->assertEqualsWithDelta(2.35, $alerts[0]->lon ?? 0.0, 0.01);
    }

    #[Test]
    public function warningMessageContainsTranslationKey(): void
    {
        $stage = $this->createStage(lat: 48.85, lon: 2.35);

        $this->riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(22.0);

        $alerts = $this->analyzer->analyze($stage, [
            'startDate' => new DateTimeImmutable('2024-12-15', new DateTimeZone('UTC')),
            'stageIndex' => 0,
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('alert.sunset.warning', $alerts[0]->message);
    }

    #[Test]
    public function noAlertWhenNoStartDateAndArrivalBeforeTwilight(): void
    {
        // Uses today's date as fallback — just check that it doesn't crash with null startDate
        $stage = $this->createStage();

        // Arrive at departure hour (0 riding time — before any twilight)
        $this->riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(8.0);

        $alerts = $this->analyzer->analyze($stage, [
            'startDate' => null,
            'stageIndex' => 0,
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        // With arrival at 08:00, always before twilight end regardless of date/location
        $this->assertSame([], $alerts);
    }

    #[Test]
    public function usesLocaleFromContext(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')->with(
            'alert.sunset.warning',
            $this->anything(),
            'alerts',
            'fr',
        )->willReturn('Alerte coucher de soleil');

        /** @var Stub&RiderTimeEstimatorInterface $riderTimeEstimator */
        $riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(22.0);

        $analyzer = new SunsetAlertAnalyzer($riderTimeEstimator, $translator);
        $stage = $this->createStage(lat: 48.85, lon: 2.35);

        $alerts = $analyzer->analyze($stage, [
            'startDate' => new DateTimeImmutable('2024-12-15', new DateTimeZone('UTC')),
            'stageIndex' => 0,
            'locale' => 'fr',
        ]);

        $this->assertCount(1, $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, SunsetAlertAnalyzer::getPriority());
    }

    #[Test]
    public function stageIndexOffsetsDays(): void
    {
        // Stage index 5 = 5 days after startDate
        // Regardless of exact dates, it should not throw and should use the correct date
        $stage = $this->createStage(lat: 48.85, lon: 2.35);

        $this->riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(8.0);

        $alerts = $this->analyzer->analyze($stage, [
            'startDate' => new DateTimeImmutable('2024-06-01', new DateTimeZone('UTC')),
            'stageIndex' => 5,
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        // 08:00 arrival in June is well before any twilight — should be empty
        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertForPolarNight(): void
    {
        // North Pole (89°N) in December — polar night, date_sun_info returns false for civil_twilight_end
        $stage = $this->createStage(lat: 89.0, lon: 0.0);

        // estimateTimeAtDistance should NOT be called for polar conditions
        $riderTimeEstimator = $this->createMock(RiderTimeEstimatorInterface::class);
        $riderTimeEstimator->expects($this->never())->method('estimateTimeAtDistance');
        $analyzer = new SunsetAlertAnalyzer($riderTimeEstimator, $this->translator);

        $alerts = $analyzer->analyze($stage, [
            'startDate' => new DateTimeImmutable('2024-12-15', new DateTimeZone('UTC')),
            'stageIndex' => 0,
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        $this->assertSame([], $alerts);
    }

    private function createStage(
        float $lat = 45.0,
        float $lon = 5.0,
        float $distance = 80.0,
        float $elevation = 500.0,
        bool $isRestDay = false,
    ): Stage {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: $distance,
            elevation: $elevation,
            startPoint: new Coordinate(44.0, 4.0),
            endPoint: new Coordinate($lat, $lon),
            isRestDay: $isRestDay,
        );
    }
}
