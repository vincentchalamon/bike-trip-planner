<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\ApiResource\Model\AlertActionKind;
use App\Message\CheckCalendar;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yasumi\Yasumi;

#[AsMessageHandler]
final readonly class CheckCalendarHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager);
    }

    public function __invoke(CheckCalendar $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
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
                $isHoliday = $holidays->isHoliday($stageDate);
                $isSunday = '7' === $stageDate->format('N');

                if ($isHoliday) {
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
                        'type' => 'holiday',
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
                        'action' => [
                            'kind' => AlertActionKind::DISMISS->value,
                            'label' => $this->translator->trans('alert.calendar.action', [], 'alerts', $locale),
                            'payload' => [],
                        ],
                    ];
                } elseif ($isSunday) {
                    $nudges[] = [
                        'stageIndex' => $i,
                        'type' => 'sunday',
                        'date' => $stageDate->format('Y-m-d'),
                        'message' => $this->translator->trans(
                            'alert.calendar.sunday_nudge',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                        'action' => [
                            'kind' => AlertActionKind::DISMISS->value,
                            'label' => $this->translator->trans('alert.calendar.action', [], 'alerts', $locale),
                            'payload' => [],
                        ],
                    ];
                }
            }

            $this->publisher->publish($tripId, MercureEventType::CALENDAR_ALERTS, [
                'nudges' => $nudges,
            ]);
        }, $generation);
    }
}
