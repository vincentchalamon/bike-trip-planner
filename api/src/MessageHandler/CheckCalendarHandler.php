<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCalendar;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Yasumi\Yasumi;

#[AsMessageHandler]
final readonly class CheckCalendarHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(CheckCalendar $message): void
    {
        $tripId = $message->tripId;
        $request = $this->tripStateManager->getRequest($tripId);
        $stages = $this->tripStateManager->getStages($tripId);

        if (in_array(null, [$request, $stages, $request?->startDate], true)) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::CALENDAR, function () use ($tripId, $request, $stages): void {
            $startDate = $request->startDate;
            \assert($startDate instanceof \DateTimeImmutable);
            $year = (int) $startDate->format('Y');

            $holidays = Yasumi::create('France', $year);

            $nudges = [];

            foreach ($stages as $i => $stage) {
                $stageDate = $startDate->modify(\sprintf('+%d days', $i));

                if ($holidays->isHoliday($stageDate)) {
                    $holiday = $holidays->getHoliday($stageDate->format('Y-m-d'));
                    $nudges[] = [
                        'stageIndex' => $i,
                        'date' => $stageDate->format('Y-m-d'),
                        'holiday' => $holiday?->getName() ?? 'Jour férié',
                        'message' => \sprintf(
                            'Étape %d coïncide avec un jour férié (%s). Certains commerces peuvent être fermés.',
                            $stage->dayNumber,
                            $holiday?->getName() ?? 'Jour férié',
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
