<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\TripModification;
use App\Enum\ComputationName;
use App\Enum\ComputationTrigger;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;

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
 * - 'dates':         FetchWeather, CheckCalendar, ScanEvents, CheckCulturalPois
 * - 'pacing':        RecalculateStages (all stages)
 */
final readonly class ComputationDependencyResolver
{
    /**
     * Pointless on a trip with no start date: each needs a calendar date to resolve against.
     *
     * @var list<ComputationName>
     */
    private const array REQUIRES_DATES = [
        ComputationName::WEATHER,
        ComputationName::CALENDAR,
        ComputationName::EVENTS,
    ];

    public function __construct(
        private EnrichmentMessageFactory $messageFactory,
    ) {
    }

    /**
     * Adds every enrichment the trigger invalidates.
     *
     * A trip with no dates is dropped from the date-driven ones: there is no stage date to
     * compute a forecast, a holiday or an event against.
     *
     * @param array<string, ComputationName> $needed
     *
     * @return array<string, ComputationName>
     */
    private function add(array $needed, ComputationTrigger $trigger, bool $hasDates): array
    {
        foreach (ComputationName::dependingOn($trigger) as $computation) {
            if (!$hasDates && \in_array($computation, self::REQUIRES_DATES, true)) {
                continue;
            }

            $needed[$computation->value] = $computation;
        }

        return $needed;
    }

    /**
     * The modification names a stage by identity; the dependency rules are expressed in
     * terms of "and the ones after it", so the identity is resolved against the current
     * order. A stage that no longer exists contributes nothing.
     *
     * @param list<string> $stageIds
     */
    private function positionOf(array $stageIds, ?string $stageId): ?int
    {
        if (null === $stageId) {
            return null;
        }

        $position = array_search($stageId, $stageIds, true);

        return false === $position ? null : $position;
    }

    /**
     * @param list<TripModification> $modifications
     * @param list<string>           $stageIds                  stage identifiers, in display order, so "this stage
     *                                                          and the ones after it" can still be expressed
     * @param list<string>           $enabledAccommodationTypes
     *
     * @return list<object> Messenger messages to dispatch
     */
    public function resolve(
        string $tripId,
        array $modifications,
        array $stageIds,
        bool $hasDates,
        array $enabledAccommodationTypes,
        ?int $generation,
    ): array {
        $messages = [];
        $recalcIndices = [];
        $accommodationScanIndices = [];
        // Which enrichments a modification invalidates is declared on
        // ComputationName::triggers(), not listed here (ADR-070). Ten booleans holding the
        // same knowledge is how this class came to miss the same three computations on a
        // date change as the other resolver did, independently.
        /** @var array<string, ComputationName> $needed */
        $needed = [];
        $datesAlsoShift = false;

        foreach ($modifications as $modification) {
            switch ($modification->type) {
                case 'accommodation':
                    $position = $this->positionOf($stageIds, $modification->stageId);
                    if (null !== $position) {
                        $recalcIndices[] = $position;
                        // Also recalculate the next stage (its startPoint may shift)
                        if (isset($stageIds[$position + 1])) {
                            $recalcIndices[] = $position + 1;
                        }

                        $accommodationScanIndices[] = $position;
                    }

                    break;

                case 'distance':
                    $position = $this->positionOf($stageIds, $modification->stageId);
                    if (null !== $position) {
                        // Distance change affects the modified stage and all subsequent
                        $affected = array_filter(
                            array_keys($stageIds),
                            static fn (int $i): bool => $i >= $position,
                        );
                        array_push($recalcIndices, ...array_values($affected));
                        foreach ($affected as $idx) {
                            $accommodationScanIndices[] = $idx;
                        }
                    }

                    // Nothing added here: the RecalculateStages built below carries
                    // ComputationTrigger::GEOMETRY, and its handler dispatches that set.
                    // Listing it again is what dispatched the overlap twice.
                    break;

                case 'dates':
                    // Dates alone recalculate no stage, so there is no RecalculateStages to
                    // carry the trigger and the set is emitted directly.
                    $needed = $this->add($needed, ComputationTrigger::DATES, $hasDates);
                    break;

                case 'pacing':
                    // Pacing changes affect all stages (fatigue factor, elevation penalty, etc.)
                    array_push($recalcIndices, ...array_keys($stageIds));
                    $datesAlsoShift = true;
                    break;
            }
        }

        // Deduplicate and sort affected indices
        /** @var list<int> $recalcIndices */
        $recalcIndices = array_values(array_unique($recalcIndices));
        sort($recalcIndices);
        /** @var list<int> $accommodationScanIndices */
        $accommodationScanIndices = array_values(array_unique($accommodationScanIndices));

        // Resolve positions to identities here, once: the messages are consumed after
        // further edits may have reordered the collection.
        $recalcStageIds = array_values(array_filter(array_map(
            static fn (int $index): ?string => $stageIds[$index] ?? null,
            $recalcIndices,
        )));

        // Build RecalculateStages message (skip accommodation scan since we handle it separately)
        if ([] !== $recalcStageIds) {
            $messages[] = new RecalculateStages(
                $tripId,
                $recalcStageIds,
                skipAccommodationScan: true,
                // Re-pacing moves every stage onto a different date as well as a different
                // line; a distance edit keeps the stage count, so no date moves.
                triggers: $datesAlsoShift
                    ? [ComputationTrigger::GEOMETRY, ComputationTrigger::DATES]
                    : [ComputationTrigger::GEOMETRY],
                generation: $generation,
            );
        }

        // Build per-stage ScanAccommodations messages
        foreach ($accommodationScanIndices as $idx) {
            if (!isset($stageIds[$idx])) {
                continue;
            }

            $messages[] = new ScanAccommodations(
                $tripId,
                stageId: $stageIds[$idx],
                enabledAccommodationTypes: $enabledAccommodationTypes,
                generation: $generation,
            );
        }

        // Accommodations are dispatched per stage just above, so the trip-wide one the
        // table would produce is dropped here.
        unset($needed[ComputationName::ACCOMMODATIONS->value]);

        foreach ($needed as $computation) {
            $messages[] = $this->messageFactory->create(
                $computation,
                $tripId,
                $generation,
                $enabledAccommodationTypes,
            );
        }

        return $messages;
    }
}
