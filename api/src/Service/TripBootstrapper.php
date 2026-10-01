<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Enum\TripStatus;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The three steps every trip goes through before its enrichments, whichever door it came in by.
 *
 * A trip is created from a source URL ({@see \App\State\TripCreateProcessor}, the route then
 * fetched by {@see \App\MessageHandler\FetchAndParseRouteHandler} and paced by
 * {@see \App\MessageHandler\GenerateStagesHandler}) or from a GPX file ({@see GpxUploadService},
 * which runs all three inside the request, ADR-043). The two paths used to carry their own copy
 * of each step, kept in line by comments saying "mirroring"; they now share these.
 *
 * Computation tracking stays with the callers: the workers wrap each step in
 * `executeWithTracking()`, the upload marks its structural steps by hand.
 */
final readonly class TripBootstrapper
{
    public function __construct(
        private TripRequestRepositoryInterface $trips,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
        private TransientTripPointsStoreInterface $points,
        private RouteSimplifierInterface $routeSimplifier,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private TripUpdatePublisherInterface $publisher,
        private TripStageStoreInterface $stageStore,
        private StructuralComputationService $structuralComputation,
    ) {
    }

    /**
     * Persists a new trip owned by $owner and starts tracking the whole pipeline as pending.
     *
     * The owner is set before the row is written: without it TripVoter denies the creator's own
     * GET, hidden as a 404 (ADR-038, recette #649).
     *
     * @return string the new trip's identifier
     */
    public function create(TripRequest $request, User $owner, string $locale): string
    {
        $tripId = Uuid::v7()->toRfc4122();

        $request->user = $owner;
        $this->trips->initializeTrip($tripId, $request, $locale);
        $this->computationTracker->initializeComputations($tripId, ComputationName::pipeline());
        $this->generationTracker->initialize($tripId);

        return $tripId;
    }

    /**
     * Stores a parsed route (raw and decimated points, source type, title) and publishes
     * `route_parsed` with its totals.
     *
     * @param list<Coordinate> $points
     *
     * @return array{totalDistance: float, totalElevation: int, totalElevationLoss: int}
     */
    public function storeRoute(string $tripId, array $points, SourceType $sourceType, ?string $title): array
    {
        $this->points->storeRawPoints($tripId, array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $points,
        ));

        $this->trips->storeSourceType($tripId, $sourceType->value);
        $this->trips->storeTitle($tripId, $title);

        $this->points->storeDecimatedPoints($tripId, array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $this->routeSimplifier->simplify($points),
        ));

        $totals = [
            'totalDistance' => round($this->distanceCalculator->calculateTotalDistance($points), 1),
            'totalElevation' => (int) $this->elevationCalculator->calculateTotalAscent($points),
            'totalElevationLoss' => (int) $this->elevationCalculator->calculateTotalDescent($points),
        ];

        $this->publisher->publish($tripId, MercureEventType::ROUTE_PARSED, [
            ...$totals,
            'sourceType' => $sourceType->value,
            'title' => $title,
        ]);

        return $totals;
    }

    /**
     * Paces the stored route into stages, persists them and publishes `stages_computed`.
     *
     * Below {@see TripStatus::MIN_STAGES} the trip stays a draft and a validation error says
     * why; at or above it the trip is structurally `ready` (ADR-043) — independently of the
     * enrichments, so a trip without dates still gets there.
     *
     * Hands back, with the published stages, the generation the write produced: writing the
     * collection bumps the trip version, so whatever the caller dispatches next must carry
     * this one — not the generation it started from, which is now stale (ADR-073).
     *
     * @return array{stages: list<array<string, mixed>>, generation: int|null} the stages in the event's shape; a null generation means the trip is gone
     */
    public function storeStages(string $tripId, TripRequest $request): array
    {
        $stages = $this->structuralComputation->generateStages($tripId, $request);

        // Storing a collection replaces the previous one. A generator that found no route to
        // pace must not be read as "this trip has no stages": that is how a re-pacing once the
        // route points had expired deleted every stage of the trip (#1405).
        $existing = [] === $stages ? \count($this->stageStore->getStages($tripId) ?? []) : 0;
        if ($existing > 0) {
            throw new \LogicException(\sprintf('Pacing trip %s produced no stage; refusing to replace its %d stages with none.', $tripId, $existing));
        }

        if (\count($stages) < TripStatus::MIN_STAGES) {
            $this->publisher->publishValidationError($tripId, 'MIN_STAGES', 'A minimum of 2 stages is required.');
        }

        $generation = $this->stageStore->storeStages($tripId, $stages);

        if (\count($stages) >= TripStatus::MIN_STAGES) {
            $this->trips->storeStatus($tripId, TripStatus::READY->value);
        }

        $serialized = $this->structuralComputation->serializeStagesForEvent($stages);
        $this->publisher->publish($tripId, MercureEventType::STAGES_COMPUTED, ['stages' => $serialized]);

        return ['stages' => $serialized, 'generation' => $generation];
    }
}
