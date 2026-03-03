<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\GpxWriter\GpxWriterInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\GenerateStageGpx;
use App\Message\ScanAccommodations;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class GenerateStageGpxHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private GpxWriterInterface $gpxWriter,
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(GenerateStageGpx $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::STAGE_GPX, function () use ($tripId, $stages, $locale): void {
            foreach ($stages as $i => $stage) {
                $gpxContent = $this->gpxWriter->generate(
                    $stage->geometry,
                    $this->translator->trans('stage.label', ['%day%' => $stage->dayNumber], 'alerts', $locale),
                );

                $stage->gpxContent = $gpxContent;

                $this->publisher->publish($tripId, MercureEventType::STAGE_GPX_READY, [
                    'stageIndex' => $i,
                    'gpxContent' => $gpxContent,
                ]);
            }

            $this->tripStateManager->storeStages($tripId, $stages);

            $this->messageBus->dispatch(new ScanPois($tripId));
            $this->messageBus->dispatch(new ScanAccommodations($tripId));
            $this->messageBus->dispatch(new AnalyzeTerrain($tripId));
            $this->messageBus->dispatch(new FetchWeather($tripId));
            $this->messageBus->dispatch(new CheckCalendar($tripId));
            $this->messageBus->dispatch(new CheckBikeShops($tripId));
        });
    }
}
