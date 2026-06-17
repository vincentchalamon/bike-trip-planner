<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckFerries;
use App\Osm\FerryRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flags stages whose route takes a ferry crossing.
 *
 * For each stage, looks up the local osm.ferries lines (route=ferry) running
 * within a tolerance of the stage geometry (ADR-040). A ferry on a stage emits a
 * warning — ferries run on schedules, may require booking and can block progress.
 * Deduplicates per stage by ferry name.
 */
#[AsMessageHandler]
final readonly class CheckFerriesHandler extends AbstractTripMessageHandler
{
    /** Max distance (m) between the stage line and a ferry line to count the stage as taking it. */
    private const int FERRY_TOLERANCE_METERS = 100;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private FerryRepositoryInterface $ferryRepository,
        private TranslatorInterface $translator,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(CheckFerries $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::FERRIES, function () use ($tripId, $stages, $locale): void {
            $alerts = [];

            foreach ($stages as $i => $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                $stagePoints = array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                    $stage->geometry,
                );

                /** @var list<string> $seenNames */
                $seenNames = [];
                foreach ($this->ferryRepository->findNearStage($stagePoints, self::FERRY_TOLERANCE_METERS) as $ferry) {
                    $key = $ferry['name'] ?? \sprintf('%.5F,%.5F', $ferry['lat'], $ferry['lon']);
                    if (\in_array($key, $seenNames, true)) {
                        continue;
                    }

                    $seenNames[] = $key;

                    $alerts[] = [
                        'stageIndex' => $i,
                        'dayNumber' => $stage->dayNumber,
                        'type' => AlertType::WARNING->value,
                        'message' => $this->translator->trans(
                            'alert.ferry.warning',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                        'action' => [
                            'kind' => AlertActionKind::NAVIGATE->value,
                            'label' => $this->translator->trans('alert.ferry.action', [], 'alerts', $locale),
                            'payload' => ['lat' => $ferry['lat'], 'lon' => $ferry['lon']],
                        ],
                        'lat' => $ferry['lat'],
                        'lon' => $ferry['lon'],
                    ];
                }
            }

            $this->publisher->publish($tripId, MercureEventType::FERRY_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }
}
