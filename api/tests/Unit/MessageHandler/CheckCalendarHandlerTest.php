<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCalendar;
use App\MessageHandler\CheckCalendarHandler;
use App\Osm\AdminBoundaryRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckCalendarHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function holidayNameProvider(): iterable
    {
        yield 'french' => ['fr', "L'étape 1 coïncide avec un jour férié (La Fête nationale). Certains commerces peuvent être fermés."];
        yield 'english' => ['en', 'Stage 1 coincides with a public holiday (Bastille Day). Some businesses may be closed.'];
    }

    #[DataProvider('holidayNameProvider')]
    #[Test]
    public function holidayNameIsInTheMessageLocale(string $locale, string $expected): void
    {
        // 2026-07-14 (Bastille Day) is a Tuesday, so only the holiday nudge fires.
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-07-14');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($request);
        $tripStateManager->method('getStages')->willReturn([$this->createStage('trip-1', 1)]);
        $tripStateManager->method('getLocale')->willReturn($locale);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::CALENDAR_ALERTS,
                $this->callback(static function (array $data) use ($expected): bool {
                    self::assertSame($expected, $data['alerts'][0]['message']);

                    return true;
                }),
            );

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->adminBoundaryRepository(['FR']),
            $this->createAlertTranslator(),
        );
        $handler(new CheckCalendar('trip-1'));
    }

    private function createStage(string $tripId, int $dayNumber, float $lat = 48.0, float $lon = 2.0): Stage
    {
        return new Stage(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 80000.0,
            elevation: 500.0,
            startPoint: new Coordinate($lat, $lon),
            endPoint: new Coordinate($lat + 0.1, $lon + 0.1),
        );
    }

    /**
     * @param list<Stage> $stages
     */
    private function tripStateManager(array $stages, \DateTimeImmutable $startDate): TripRequestRepositoryInterface
    {
        $request = new TripRequest();
        $request->startDate = $startDate;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($request);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        return $tripStateManager;
    }

    /**
     * @param list<string|null> $countryCodes cycled over the checked points
     */
    private function adminBoundaryRepository(array $countryCodes): AdminBoundaryRepositoryInterface
    {
        $call = 0;
        $repository = $this->createStub(AdminBoundaryRepositoryInterface::class);
        $repository->method('findCountryCodeAt')->willReturnCallback(
            static function () use ($countryCodes, &$call): ?string {
                $code = $countryCodes[$call % \count($countryCodes)];
                ++$call;

                return $code;
            },
        );

        return $repository;
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        AdminBoundaryRepositoryInterface $adminBoundaryRepository,
        ?TranslatorInterface $translator = null,
    ): CheckCalendarHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $stubTranslator = $this->createStub(TranslatorInterface::class);
        $stubTranslator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.calendar.sunday_nudge' => \sprintf('Stage %s falls on a Sunday.', $params['%stage%']),
                'alert.calendar.nudge' => \sprintf('Stage %s: holiday %s.', $params['%stage%'], $params['%holiday%']),
                'alert.calendar.unnamed_nudge' => \sprintf('Stage %s: public holiday.', $params['%stage%']),
                default => $id,
            },
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new CheckCalendarHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $adminBoundaryRepository,
            $translator ?? $stubTranslator,
            $this->createStub(MessageBusInterface::class),
        );
    }

    /**
     * Runs the handler and returns the published alert payload.
     *
     * @param list<Stage>       $stages
     * @param list<string|null> $countryCodes
     *
     * @return list<array<string, mixed>>
     */
    private function publishedAlerts(array $stages, \DateTimeImmutable $startDate, array $countryCodes): array
    {
        $captured = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            static function (string $tripId, MercureEventType $type, array $payload) use (&$captured): void {
                self::assertSame(MercureEventType::CALENDAR_ALERTS, $type);
                \assert(\is_array($payload['alerts']));
                $captured = array_values($payload['alerts']);
            },
        );

        $handler = $this->createHandler(
            $this->tripStateManager($stages, $startDate),
            $publisher,
            $this->adminBoundaryRepository($countryCodes),
        );
        $handler(new CheckCalendar('trip-1'));

        /** @var list<array<string, mixed>> $alerts */
        $alerts = $captured;

        return $alerts;
    }

    #[Test]
    public function sundayNonHolidayEmitsSundayNudge(): void
    {
        // 2026-03-15 is a Sunday, not a French holiday
        $alerts = $this->publishedAlerts(
            [$this->createStage('trip-1', 1)],
            new \DateTimeImmutable('2026-03-15'),
            ['FR'],
        );

        $this->assertCount(1, $alerts);
        $this->assertSame(0, $alerts[0]['stageIndex']);
        $this->assertSame(1, $alerts[0]['dayNumber']);
        $this->assertSame('nudge', $alerts[0]['type']);
        $this->assertSame('2026-03-15', $alerts[0]['date']);
        $this->assertIsString($alerts[0]['message']);
        $this->assertStringContainsString('Sunday', $alerts[0]['message']);
        \assert(\is_array($alerts[0]['action']));
        $this->assertSame('dismiss', $alerts[0]['action']['kind']);
    }

    #[Test]
    public function sundayHolidayEmitsOnlyHolidayNudge(): void
    {
        // 2023-01-01 is a Sunday and New Year's Day in France
        $alerts = $this->publishedAlerts(
            [$this->createStage('trip-1', 1)],
            new \DateTimeImmutable('2023-01-01'),
            ['FR'],
        );

        $this->assertCount(1, $alerts);
        $this->assertSame('nudge', $alerts[0]['type']);
        $this->assertIsString($alerts[0]['message']);
        $this->assertStringContainsString('holiday', $alerts[0]['message']);
        $this->assertStringNotContainsString('Sunday', $alerts[0]['message']);
    }

    #[Test]
    public function weekdayNonHolidayEmitsNoNudge(): void
    {
        // 2026-03-10 is a Tuesday, not a holiday
        $alerts = $this->publishedAlerts(
            [$this->createStage('trip-1', 1)],
            new \DateTimeImmutable('2026-03-10'),
            ['FR'],
        );

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function crossBorderTripGetsHolidaysOfBothCountries(): void
    {
        // 2026-07-14 (Tue) is Bastille Day: French only.
        // 2026-10-12 (Mon) is the Spanish national day: Spanish only.
        // Resolving both countries must yield both, where a single provider misses one.
        $stage = [$this->createStage('trip-1', 1)];

        $this->assertSame([], $this->publishedAlerts($stage, new \DateTimeImmutable('2026-07-14'), ['ES']));
        $this->assertCount(1, $this->publishedAlerts($stage, new \DateTimeImmutable('2026-07-14'), ['FR', 'ES']));

        $this->assertSame([], $this->publishedAlerts($stage, new \DateTimeImmutable('2026-10-12'), ['FR']));
        $this->assertCount(1, $this->publishedAlerts($stage, new \DateTimeImmutable('2026-10-12'), ['FR', 'ES']));
    }

    #[Test]
    public function tripStraddlingTwoYearsGetsBothYears(): void
    {
        // 28 December 2026 → 4 January 2027: Christmas is behind, but 1 January 2027
        // belongs to the next year's holiday set.
        $stages = [];
        for ($i = 1; $i <= 8; ++$i) {
            $stages[] = $this->createStage('trip-1', $i);
        }

        $alerts = $this->publishedAlerts($stages, new \DateTimeImmutable('2026-12-28'), ['FR']);

        $dates = array_column($alerts, 'date');
        $this->assertContains('2027-01-01', $dates, "New Year's Day 2027 must be detected on a trip starting in 2026");
        // 2027-01-03 is a Sunday, proving the loop keeps running across the year change.
        $this->assertContains('2027-01-03', $dates);
    }

    #[Test]
    public function unresolvedCountryFallsBackToFrance(): void
    {
        // No admin_level=2 boundary resolves (regional OSM extract): the pre-existing
        // French behaviour must be preserved rather than reporting no holiday at all.
        $alerts = $this->publishedAlerts(
            [$this->createStage('trip-1', 1)],
            new \DateTimeImmutable('2026-07-14'),
            [null],
        );

        $this->assertCount(1, $alerts);
        $this->assertIsString($alerts[0]['message']);
        $this->assertStringContainsString('holiday', $alerts[0]['message']);
    }

    #[Test]
    public function countryWithoutHolidayProviderFallsBackToFrance(): void
    {
        // 'ZZ' has no Yasumi provider: rather than losing every holiday, France applies.
        $alerts = $this->publishedAlerts(
            [$this->createStage('trip-1', 1)],
            new \DateTimeImmutable('2026-07-14'),
            ['ZZ'],
        );

        $this->assertCount(1, $alerts);
        $this->assertIsString($alerts[0]['message']);
        $this->assertStringContainsString('holiday', $alerts[0]['message']);
    }

    #[Test]
    public function messageNeverUsesATautologicalHolidayName(): void
    {
        $alerts = $this->publishedAlerts(
            [$this->createStage('trip-1', 1)],
            new \DateTimeImmutable('2026-07-14'),
            ['FR'],
        );

        $this->assertCount(1, $alerts);
        $this->assertIsString($alerts[0]['message']);
        $this->assertStringNotContainsString('(Public holiday)', $alerts[0]['message']);
        $this->assertStringNotContainsString('(Jour férié)', $alerts[0]['message']);
    }
}
