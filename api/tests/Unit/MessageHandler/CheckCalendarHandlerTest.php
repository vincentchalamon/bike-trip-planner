<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use DateTimeImmutable;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckCalendarHandlerTest extends TestCase
{
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
    ): CheckCalendarHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
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
            $translator,
        );
    }

    #[Test]
    public function sundayNonHolidayEmitsSundayNudge(): void
    {
        // 2026-03-15 is a Sunday, not a French holiday
        $request = new TripRequest();
        $request->startDate = new DateTimeImmutable('2026-03-15');

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
                        && str_contains((string) $nudges[0]['message'], 'Sunday');
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
        $request->startDate = new DateTimeImmutable('2023-01-01');

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
                        && 0 === $nudges[0]['stageIndex'];
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
        $request->startDate = new DateTimeImmutable('2026-03-10');

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
