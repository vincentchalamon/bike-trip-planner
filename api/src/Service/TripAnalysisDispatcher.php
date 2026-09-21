<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\TripRequest;
use App\Enum\ComputationName;
use App\Enum\ComputationTrigger;
use App\Message\ResolveStageLabels;
use App\Message\ScanAccommodations;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The one place that turns "this changed" into enrichment messages.
 *
 * Which enrichments an edit invalidates is declared once, on
 * {@see ComputationName::triggers()}; this class only knows how to build each message. Before
 * ADR-070 the two halves were fused and duplicated across four call sites that had drifted
 * apart, so a stage merge re-ran five computations out of twelve and nobody could see it.
 */
final readonly class TripAnalysisDispatcher
{
    /**
     * Needs a calendar date to resolve against, so a trip without a start date gets none of
     * them. Dispatching them anyway would not be merely wasteful: each falls back to today
     * rather than skipping, so the trip would keep a holiday or a forecast dated from
     * whenever it happened to be edited (ADR-070).
     *
     * @var list<ComputationName>
     */
    private const array REQUIRES_A_START_DATE = [
        ComputationName::WEATHER,
        ComputationName::CALENDAR,
        ComputationName::EVENTS,
    ];

    public function __construct(
        private MessageBusInterface $messageBus,
        private EnrichmentMessageFactory $messageFactory,
    ) {
    }

    /**
     * Dispatches every enrichment for the given trip — the full pipeline, after stages are
     * generated or on an explicit `POST /trips/{id}/analyze`.
     */
    public function dispatch(string $tripId, TripRequest $request, ?int $generation = null): void
    {
        $this->dispatchFor(
            $tripId,
            $request,
            [ComputationTrigger::GEOMETRY, ComputationTrigger::DATES],
            $generation,
        );
    }

    /**
     * Dispatches the enrichments that the given triggers invalidate, and only those.
     *
     * @param list<ComputationTrigger> $triggers
     * @param list<string>             $scopedStageIds Restricts the computations that accept a
     *                                                 stage; empty means the whole trip
     * @param list<ComputationName>    $except         Held back even though the triggers cover
     *                                                 them — an accommodation edit moves the
     *                                                 line but must not re-scan over the
     *                                                 choice the rider just made
     */
    public function dispatchFor(
        string $tripId,
        TripRequest $request,
        array $triggers,
        ?int $generation = null,
        array $scopedStageIds = [],
        array $except = [],
    ): void {
        foreach (ComputationName::dependingOn(...$triggers) as $computation) {
            if (\in_array($computation, $except, true)) {
                continue;
            }

            if (ComputationName::ACCOMMODATIONS === $computation && [] !== $scopedStageIds) {
                foreach ($scopedStageIds as $stageId) {
                    $this->messageBus->dispatch(new ScanAccommodations(
                        $tripId,
                        stageId: $stageId,
                        enabledAccommodationTypes: $request->enabledAccommodationTypes,
                        generation: $generation,
                    ));
                }

                continue;
            }

            $this->dispatchOne($tripId, $request, $computation, $generation);
        }

        // Reverse-geocoded end-point labels move with the line, so they belong to the geometry
        // set — but they are not a tracked computation, so they have no ComputationName to
        // hang a trigger off and are named here instead.
        if (\in_array(ComputationTrigger::GEOMETRY, $triggers, true)) {
            $this->messageBus->dispatch(new ResolveStageLabels($tripId, $generation));
        }
    }

    /**
     * Dispatches a single enrichment, for callers that resolved one by name.
     *
     * Carries the start-date guard rather than leaving it to {@see dispatchFor()}, which
     * funnels through here: this is also the method a PATCH reaches, and a PATCH is how a
     * trip loses its dates.
     */
    public function dispatchOne(
        string $tripId,
        TripRequest $request,
        ComputationName $computation,
        ?int $generation = null,
    ): void {
        if (!$request->startDate instanceof \DateTimeImmutable && \in_array($computation, self::REQUIRES_A_START_DATE, true)) {
            return;
        }

        $this->messageBus->dispatch($this->messageFactory->create(
            $computation,
            $tripId,
            $generation,
            $request->enabledAccommodationTypes,
        ));
    }
}
