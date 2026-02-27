<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCalendar;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yasumi\Yasumi;

#[AsMessageHandler]
final readonly class CheckCalendarHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(CheckCalendar $message): void
    {
        $tripId = $message->tripId;
        $request = $this->tripStateManager->getRequest($tripId);
        $stages = $this->tripStateManager->getStages($tripId);

        if (!$request instanceof TripRequest || null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::CALENDAR, function () use ($tripId, $request, $stages, $locale): void {
            $startDate = $request->startDate ?? new \DateTimeImmutable('today');
            $year = (int) $startDate->format('Y');

            $holidays = Yasumi::create('France', $year);

            $nudges = [];

            foreach ($stages as $i => $stage) {
                $stageDate = $startDate->modify(\sprintf('+%d days', $i));

                if ($holidays->isHoliday($stageDate)) {
                    // Find the matching holiday name by iterating
                    $holidayName = null;
                    foreach ($holidays->getHolidays() as $holiday) {
                        if ($holiday->format('Y-m-d') === $stageDate->format('Y-m-d')) {
                            $holidayName = $holiday->getName();
                            break;
                        }
                    }

                    $fallback = $this->translator->trans('alert.calendar.fallback', [], 'alerts', $locale);
                    $nudges[] = [
                        'stageIndex' => $i,
                        'date' => $stageDate->format('Y-m-d'),
                        'holiday' => $holidayName ?? $fallback,
                        'message' => $this->translator->trans(
                            'alert.calendar.nudge',
                            [
                                '%stage%' => $stage->dayNumber,
                                '%holiday%' => $holidayName ?? $fallback,
                            ],
                            'alerts',
                            $locale,
                        ),
                    ];
                }
            }

            $this->publisher->publish($tripId, MercureEventType::CALENDAR_ALERTS, [
                'nudges' => $nudges,
            ]);
        });
    }
}
