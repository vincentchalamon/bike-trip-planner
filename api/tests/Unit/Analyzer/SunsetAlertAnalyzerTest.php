<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Tests\Unit\AlertMessageTestTrait;
use App\Analyzer\Rules\SunsetAlertAnalyzer;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertType;
use App\Geo\HaversineDistance;
use App\Geo\TimezoneResolver;
use App\Osm\AdminBoundaryRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SunsetAlertAnalyzerTest extends TestCase
{
    use AlertMessageTestTrait;

    private TranslatorInterface $translator;

    private Stub&RiderTimeEstimatorInterface $riderTimeEstimator;

    private SunsetAlertAnalyzer $analyzer;

    #[\Override]
    protected function setUp(): void
    {
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );

        $this->riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        $this->analyzer = new SunsetAlertAnalyzer(
            $this->riderTimeEstimator,
            $this->timezoneResolver(),
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
            'startDate' => new \DateTimeImmutable('2024-07-15', new \DateTimeZone('UTC')),
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
            'startDate' => new \DateTimeImmutable('2024-12-15', new \DateTimeZone('UTC')),
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::WARNING, $alerts[0]->type);
        $this->assertEqualsWithDelta(48.85, $alerts[0]->lat ?? 0.0, 0.01);
        $this->assertEqualsWithDelta(2.35, $alerts[0]->lon ?? 0.0, 0.01);
        $this->assertNotNull($alerts[0]->action);
        $this->assertSame(AlertActionKind::AUTO_FIX, $alerts[0]->action->kind);
        $this->assertArrayHasKey('departureHour', $alerts[0]->action->payload);
        // Suggested departure should be earlier than the current departure (8)
        $this->assertLessThanOrEqual(8, $alerts[0]->action->payload['departureHour']);
        // And at least 5 (minimum)
        $this->assertGreaterThanOrEqual(5, $alerts[0]->action->payload['departureHour']);
    }

    #[Test]
    public function displaysSunsetAndTwilightInLocalTime(): void
    {
        // Paris on 21 June 2026: sunset 21:57 CEST / 19:57 UTC, civil twilight end
        // 22:40 CEST / 20:40 UTC. The message must show the CEST times — a rider in
        // France reading "19:57" would not believe it.
        $stage = $this->createStage(lat: 48.85, lon: 2.35);

        $this->riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(23.0);

        $alerts = $this->analyzer->analyze($stage, [
            'startDate' => new \DateTimeImmutable('2026-06-21', new \DateTimeZone('UTC')),
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('21:57', $this->renderMessage($alerts[0]));
        $this->assertStringContainsString('22:40', $this->renderMessage($alerts[0]));
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
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        // With arrival at 08:00, always before twilight end regardless of date/location
        $this->assertSame([], $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(20, SunsetAlertAnalyzer::getPriority());
    }

    #[Test]
    public function dayNumberOffsetsTheStageDate(): void
    {
        // The offset used to arrive through a 'stageIndex' context key. When a rename
        // elsewhere dropped that key the `?? 0` default took over and every stage silently
        // dated from the trip start, which the old assertion (an empty alert list) could
        // not see. Reading it off the stage removes the wiring, and asserting the sunset
        // time the alert reports makes the offset observable (#1290 review).
        $this->riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(23.5);
        $startDate = new \DateTimeImmutable('2024-06-01', new \DateTimeZone('UTC'));
        $context = ['startDate' => $startDate, 'departureHour' => 8, 'averageSpeed' => 15.0];

        // Paris, day 1 (1 June) against day 100 (8 September): the sun sets over an hour
        // earlier in September, so the two alerts cannot carry the same time.
        $june = $this->analyzer->analyze($this->createStage(lat: 48.85, lon: 2.35), $context);
        $september = $this->analyzer->analyze(
            $this->createStage(lat: 48.85, lon: 2.35, dayNumber: 100),
            $context,
        );

        $this->assertCount(1, $june);
        $this->assertCount(1, $september);
        $this->assertGreaterThan(
            $september[0]->parameters['%sunset%'],
            $june[0]->parameters['%sunset%'],
        );
    }

    #[Test]
    public function noAlertForPolarNight(): void
    {
        // North Pole (89°N) in December — polar night, date_sun_info returns false for civil_twilight_end
        $stage = $this->createStage(lat: 89.0, lon: 0.0);

        // estimateTimeAtDistance should NOT be called for polar conditions
        $riderTimeEstimator = $this->createMock(RiderTimeEstimatorInterface::class);
        $riderTimeEstimator->expects($this->never())->method('estimateTimeAtDistance');
        $analyzer = new SunsetAlertAnalyzer($riderTimeEstimator, $this->timezoneResolver());

        $alerts = $analyzer->analyze($stage, [
            'startDate' => new \DateTimeImmutable('2024-12-15', new \DateTimeZone('UTC')),
            'departureHour' => 8,
            'averageSpeed' => 15.0,
        ]);

        $this->assertSame([], $alerts);
    }

    private function timezoneResolver(?string $countryCode = 'FR'): TimezoneResolver
    {
        $adminBoundaryRepository = $this->createStub(AdminBoundaryRepositoryInterface::class);
        $adminBoundaryRepository->method('findCountryCodeAt')->willReturn($countryCode);

        return new TimezoneResolver(new HaversineDistance(), $adminBoundaryRepository);
    }

    private function createStage(
        float $lat = 45.0,
        float $lon = 5.0,
        float $distance = 80.0,
        float $elevation = 500.0,
        bool $isRestDay = false,
        int $dayNumber = 1,
    ): Stage {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: $dayNumber,
            distance: $distance,
            elevation: $elevation,
            startPoint: new Coordinate(44.0, 4.0),
            endPoint: new Coordinate($lat, $lon),
            isRestDay: $isRestDay,
        );
    }
}
