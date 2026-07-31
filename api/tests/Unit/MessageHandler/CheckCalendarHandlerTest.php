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
                    self::assertSame($expected, $data['nudges'][0]['message']);

                    return true;
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $this->createAlertTranslator());
        $handler(new CheckCalendar('trip-1'));
    }

    private function createStage(string $tripId, int $dayNumber): Stage
    {
        return new Stage(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 80000.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.1, 2.1),
        );
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        ?TranslatorInterface $translator = null,
    ): CheckCalendarHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $stubTranslator = $this->createStub(TranslatorInterface::class);
        $stubTranslator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.calendar.sunday_nudge' => \sprintf('Stage %s falls on a Sunday.', $params['%stage%']),
                'alert.calendar.nudge' => \sprintf('Stage %s: holiday %s.', $params['%stage%'], $params['%holiday%']),
                'alert.calendar.fallback' => 'Public holiday',
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
            $translator ?? $stubTranslator,
            $this->createStub(MessageBusInterface::class),
        );
    }

    #[Test]
    public function sundayNonHolidayEmitsSundayNudge(): void
    {
        // 2026-03-15 is a Sunday, not a French holiday
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-03-15');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($request);
        $tripStateManager->method('getStages')->willReturn([$this->createStage('trip-1', 1)]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::CALENDAR_ALERTS,
                $this->callback(static function (array $data): bool {
                    $nudges = $data['nudges'];

                    return 1 === \count($nudges)
                        && 'sunday' === $nudges[0]['type']
                        && 0 === $nudges[0]['stageIndex']
                        && str_contains((string) $nudges[0]['message'], 'Sunday')
                        && \is_array($nudges[0]['action'])
                        && 'dismiss' === $nudges[0]['action']['kind'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new CheckCalendar('trip-1'));
    }

    #[Test]
    public function sundayHolidayEmitsOnlyHolidayNudge(): void
    {
        // 2026-12-25 is a Friday... let's find a Sunday that is also a holiday in France.
        // In France, May 1st (Labour Day) — check if 2033-05-01 is a Sunday: yes!
        // Actually, let's use a simpler approach: 2022-01-01 was a Saturday... no.
        // 2023-01-01 is a Sunday and is New Year's Day (holiday in France).
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2023-01-01');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($request);
        $tripStateManager->method('getStages')->willReturn([$this->createStage('trip-1', 1)]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::CALENDAR_ALERTS,
                $this->callback(static function (array $data): bool {
                    $nudges = $data['nudges'];

                    return 1 === \count($nudges)
                        && 'holiday' === $nudges[0]['type']
                        && 0 === $nudges[0]['stageIndex']
                        && \is_array($nudges[0]['action'])
                        && 'dismiss' === $nudges[0]['action']['kind'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new CheckCalendar('trip-1'));
    }

    #[Test]
    public function weekdayNonHolidayEmitsNoNudge(): void
    {
        // 2026-03-10 is a Tuesday, not a holiday
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-03-10');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($request);
        $tripStateManager->method('getStages')->willReturn([$this->createStage('trip-1', 1)]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::CALENDAR_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['nudges']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new CheckCalendar('trip-1'));
    }
}
