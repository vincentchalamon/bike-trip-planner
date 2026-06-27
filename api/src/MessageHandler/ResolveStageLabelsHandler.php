<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\ReverseGeocoder;
use App\Message\ResolveStageLabels;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves and persists reverse-geocoded city labels for each stage endpoint
 * (recette #649, #3c/#9).
 *
 * Best-effort enrichment — not a tracked computation and not gated on: the
 * anonymous shared view and a reloaded trip read the persisted labels, while the
 * authenticated editor resolves them client-side meanwhile. Labels survive on
 * the Stage so subsequent reads render city names instead of GPS coordinates.
 */
#[AsMessageHandler]
final readonly class ResolveStageLabelsHandler
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private ReverseGeocoder $reverseGeocoder,
        private TripGenerationTrackerInterface $generationTracker,
    ) {
    }

    public function __invoke(ResolveStageLabels $message): void
    {
        $tripId = $message->tripId;

        // Skip a superseded generation: a newer recompute may have moved the stage
        // endpoints, so resolving labels for the old ones would persist them
        // against the wrong dayNumbers.
        $current = $this->generationTracker->current($tripId);
        if (null !== $message->generation && null !== $current && $message->generation < $current) {
            return;
        }

        $stages = $this->tripStateManager->getStages($tripId);
        if (null === $stages) {
            return;
        }

        foreach ($stages as $stage) {
            $startLabel = $this->reverseGeocoder->cityName($stage->startPoint->lat, $stage->startPoint->lon);
            // A rest day shares its endpoint with the previous arrival, so reuse
            // the start label instead of a second identical lookup.
            $endLabel = $stage->isRestDay
                ? $startLabel
                : $this->reverseGeocoder->cityName($stage->endPoint->lat, $stage->endPoint->lon);

            $this->tripStateManager->updateStageLabels($tripId, $stage->dayNumber, $startLabel, $endLabel);
        }
    }
}
