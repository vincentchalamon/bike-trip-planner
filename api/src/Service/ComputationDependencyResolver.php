<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\TripModification;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;

/**
 * Resolves the minimal set of Messenger messages to dispatch for a batch of modifications.
 *
 * Instead of re-running the full enrichment pipeline (as {@see TripAnalysisDispatcher} does),
 * this service fuses the dependencies of N modifications and dispatches only the handlers
 * that are actually required.
 *
 * Dependency matrix:
 * - 'accommodation': RecalculateStages (affected + next), ScanAccommodations (affected stages)
 * - 'distance':      RecalculateStages (affected + subsequent), ScanPois, ScanAccommodations,
 *                    AnalyzeTerrain, CheckBikeShops, CheckWaterPoints, CheckHealthServices,
 *                    CheckRailwayStations, FetchWeather (when dates set), CheckCalendar (when dates set)
 * - 'dates':         FetchWeather, CheckCalendar, ScanEvents
 * - 'pacing':        RecalculateStages (all stages)
 */
final readonly class ComputationDependencyResolver
{
    /**
     * @param list<TripModification> $modifications
     * @param list<int>              $allStageIndices
     * @param bool                   $hasDates
     * @param list<string>           $enabledAccommodationTypes
     *
     * @return list<object> Messenger messages to dispatch
     */
    public function resolve(
        string $tripId,
        array $modifications,
        array $allStageIndices,
        bool $hasDates,
        array $enabledAccommodationTypes,
        ?int $generation,
    ): array {
        $messages = [];
        $recalcIndices = [];
        $accommodationScanIndices = [];
        $needsPois = false;
        $needsTerrain = false;
        $needsBikeShops = false;
        $needsWaterPoints = false;
        $needsHealthServices = false;
        $needsRailwayStations = false;
        $needsWeather = false;
        $needsCalendar = false;
        $needsEvents = false;
        $needsCulturalPois = false;

        foreach ($modifications as $modification) {
            switch ($modification->type) {
                case 'accommodation':
                    if (null !== $modification->stageIndex) {
                        $recalcIndices[] = $modification->stageIndex;
                        // Also recalculate the next stage (its startPoint may shift)
                        if (isset($allStageIndices[$modification->stageIndex + 1])) {
                            $recalcIndices[] = $modification->stageIndex + 1;
                        }
                        $accommodationScanIndices[] = $modification->stageIndex;
                    }
                    break;

                case 'distance':
                    if (null !== $modification->stageIndex) {
                        // Distance change affects the modified stage and all subsequent
                        $affected = array_filter(
                            $allStageIndices,
                            static fn (int $i): bool => $i >= $modification->stageIndex,
                        );
                        array_push($recalcIndices, ...array_values($affected));
                        foreach ($affected as $idx) {
                            $accommodationScanIndices[] = $idx;
                        }
                    }
                    $needsPois = true;
                    $needsTerrain = true;
                    $needsBikeShops = true;
                    $needsWaterPoints = true;
                    $needsHealthServices = true;
                    $needsRailwayStations = true;
                    if ($hasDates) {
                        $needsWeather = true;
                        $needsCalendar = true;
                    }
                    break;

                case 'dates':
                    $needsWeather = true;
                    $needsCalendar = true;
                    $needsEvents = true;
                    $needsCulturalPois = true;
                    break;

                case 'pacing':
                    // Pacing changes affect all stages (fatigue factor, elevation penalty, etc.)
                    array_push($recalcIndices, ...$allStageIndices);
                    if ($hasDates) {
                        $needsWeather = true;
                        $needsCalendar = true;
                    }
                    break;
            }
        }

        // Deduplicate and sort affected indices
        $recalcIndices = array_values(array_unique($recalcIndices));
        sort($recalcIndices);
        $accommodationScanIndices = array_values(array_unique($accommodationScanIndices));

        // Build RecalculateStages message (skip accommodation scan since we handle it separately)
        if ([] !== $recalcIndices) {
            $messages[] = new RecalculateStages(
                $tripId,
                $recalcIndices,
                skipAccommodationScan: true,
                generation: $generation,
            );
        }

        // Build per-stage ScanAccommodations messages
        foreach ($accommodationScanIndices as $idx) {
            $messages[] = new ScanAccommodations(
                $tripId,
                stageIndex: $idx,
                enabledAccommodationTypes: $enabledAccommodationTypes,
                generation: $generation,
            );
        }

        // Build optional enrichment messages
        if ($needsPois) {
            $messages[] = new ScanPois($tripId, $generation);
        }
        if ($needsTerrain) {
            $messages[] = new AnalyzeTerrain($tripId, $generation);
        }
        if ($needsBikeShops) {
            $messages[] = new CheckBikeShops($tripId, $generation);
        }
        if ($needsWaterPoints) {
            $messages[] = new CheckWaterPoints($tripId, $generation);
        }
        if ($needsHealthServices) {
            $messages[] = new CheckHealthServices($tripId, $generation);
        }
        if ($needsRailwayStations) {
            $messages[] = new CheckRailwayStations($tripId, $generation);
        }
        if ($needsWeather) {
            $messages[] = new FetchWeather($tripId, $generation);
        }
        if ($needsCalendar) {
            $messages[] = new CheckCalendar($tripId, $generation);
        }
        if ($needsEvents) {
            $messages[] = new ScanEvents($tripId, $generation);
        }
        if ($needsCulturalPois) {
            $messages[] = new CheckCulturalPois($tripId, $generation);
        }

        return $messages;
    }
}
