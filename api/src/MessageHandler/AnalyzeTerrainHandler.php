<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Analyzer\AnalyzerRegistryInterface;
use App\ApiResource\Model\Alert;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AnalyzeTerrainHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private AnalyzerRegistryInterface $analyzerRegistry,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(AnalyzeTerrain $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';
        $request = $this->tripStateManager->getRequest($tripId);
        $ebikeMode = $request instanceof \App\ApiResource\TripRequest && $request->ebikeMode;

        $this->executeWithTracking($tripId, ComputationName::TERRAIN, function () use ($tripId, $stages, $locale, $ebikeMode): void {
            $stageCount = \count($stages);

            for ($i = 0; $i < $stageCount; ++$i) {
                $stage = $stages[$i];
                $context = [
                    'nextStage' => $stages[$i + 1] ?? null,
                    'tripDays' => $stageCount,
                    'locale' => $locale,
                    'ebikeMode' => $ebikeMode,
                ];

                $alerts = $this->analyzerRegistry->analyze($stage, $context);
                foreach ($alerts as $alert) {
                    $stage->addAlert($alert);
                }
            }

            $this->tripStateManager->storeStages($tripId, $stages);

            $alertsData = [];
            foreach ($stages as $i => $stage) {
                $alertsData[$i] = array_map(
                    static fn (Alert $a): array => ['type' => $a->type->value, 'message' => $a->message],
                    $stage->alerts,
                );
            }

            $this->publisher->publish($tripId, MercureEventType::TERRAIN_ALERTS, [
                'alertsByStage' => $alertsData,
            ]);
        });
    }
}
