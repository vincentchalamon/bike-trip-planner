<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\Stage;
use App\ApiResource\StageResponse;
use App\ApiResource\Trip;
use App\ComputationTracker\ComputationTrackerInterface;

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
    ) {
    }

    public function map(Stage $stage): StageResponse
    {
        $response = new StageResponse();
        $response->trip = new Trip(
            id: $stage->tripId,
            computationStatus: $this->computationTracker->getStatuses($stage->tripId) ?? [],
        );
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
        $response->alerts = $stage->alerts;
        $response->pois = $stage->pois;
        $response->accommodations = $stage->accommodations;
        $response->selectedAccommodation = $stage->selectedAccommodation;
        $response->events = $stage->events;

        return $response;
    }
}
