<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Alert\AlertRenderer;
use App\Alert\ReaderLocale;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Stage;
use App\ApiResource\StageResponse;
use App\ApiResource\Trip;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Repository\TripRequestRepositoryInterface;

/**
 * Builds a {@see StageResponse} from a {@see Stage} explicitly.
 *
 * The Symfony ObjectMapper `Stage -> StageResponse` mapping (class-level `#[Map]`
 * plus a `#[Map(target: 'trip', transform: ...)]` on `Stage::$tripId`) worked in
 * the test env but broke in prod: the compiled metadata dropped the property-level
 * map, fell back to a same-name `tripId` lookup on StageResponse (which exposes
 * `trip`, not `tripId`) and threw NoSuchPropertyException — every stage mutation
 * 500'd in prod (recette #649). The `#[Map]` is removed; the stage processors call
 * this builder instead, which has no metadata dependency and behaves identically
 * everywhere.
 */
final readonly class StageResponseMapper
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
        private TripRequestRepositoryInterface $trips,
        private AlertRenderer $alertRenderer,
        private ReaderLocale $readerLocale,
    ) {
    }

    public function map(Stage $stage): StageResponse
    {
        $response = new StageResponse();
        $response->trip = new Trip(
            id: $stage->tripId,
            computationStatus: $this->computationTracker->getStatuses($stage->tripId) ?? [],
            // Stated, not defaulted (ADR-074): every one of the eight stage processors calls
            // TripLocker::assertNotLocked() before it writes, so a StageResponse only ever
            // exists for a trip that is provably unlocked. Reading the lock again here would
            // buy a repository round-trip to learn what the guard has just established.
            isLocked: false,
        );
        $response->id = $stage->id;
        $response->dayNumber = $stage->dayNumber;
        $response->distance = $stage->distance;
        $response->elevation = $stage->elevation;
        $response->elevationLoss = $stage->elevationLoss;
        $response->startPoint = $stage->startPoint;
        $response->endPoint = $stage->endPoint;
        $response->geometry = $stage->geometry;
        $response->label = $stage->label;
        $response->isRestDay = $stage->isRestDay;
        $response->weather = $stage->weather;
        // Rendered for whoever asked for this response, not for whoever computed it (ADR-069).
        $response->alerts = $this->alertRenderer->render(
            $stage->alerts,
            $stage->dayNumber,
            $this->readerLocale->or($this->trips->getLocale($stage->tripId) ?? 'en'),
        );
        // Always serialize a resupply object (skip_null_values would omit a null
        // one, breaking the response schema — it is a required field like the old
        // pois array was).
        $response->resupply = $stage->resupply ?? new Resupply();
        $response->accommodations = $stage->accommodations;
        $response->selectedAccommodation = $stage->selectedAccommodation;
        $response->events = $stage->events;

        return $response;
    }
}
