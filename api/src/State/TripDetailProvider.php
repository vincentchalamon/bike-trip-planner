<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripDetail;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Enum\TripStatus;
use App\Repository\DoctrineTripRequestRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Provides a full {@see TripDetail} resource for frontend hydration.
 *
 * Loads the persisted {@see TripRequest} entity and converts its stages into
 * the JSON shape expected by the frontend Zustand store.
 *
 * @implements ProviderInterface<TripDetail>
 */
final readonly class TripDetailProvider implements ProviderInterface
{
    public function __construct(
        private DoctrineTripRequestRepository $tripStateManager,
        private TripLocker $tripLocker,
        private ComputationTrackerInterface $computationTracker,
    ) {
    }

    /**
     * @param array{id?: string}   $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripDetail
    {
        $id = $uriVariables['id'] ?? '';

        $request = $this->tripStateManager->getRequest($id);

        if (!$request instanceof TripRequest) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $id));
        }

        \assert($request->id instanceof Uuid);

        $stages = $this->tripStateManager->getStages($id) ?? [];

        $statuses = $this->computationTracker->getStatuses($id);

        return new TripDetail(
            id: $request->id->toRfc4122(),
            title: $request->title,
            sourceUrl: $request->sourceUrl,
            startDate: $request->startDate,
            endDate: $request->endDate,
            fatigueFactor: $request->fatigueFactor,
            elevationPenalty: $request->elevationPenalty,
            maxDistancePerDay: $request->maxDistancePerDay,
            averageSpeed: $request->averageSpeed,
            ebikeMode: $request->ebikeMode,
            departureHour: $request->departureHour,
            enabledAccommodationTypes: $request->enabledAccommodationTypes,
            isLocked: $this->tripLocker->isLocked($request),
            // Persisted at stage-store time (issue #775) — no PostGIS query here.
            outOfZone: $request->outOfZone,
            // Fallback for trips persisted before the status column existed: infer
            // readiness from whether stages are present.
            status: '' !== $request->status ? $request->status : ([] !== $stages ? TripStatus::READY->value : TripStatus::DRAFT->value),
            weatherStatus: $this->deriveBlockStatus($this->computationsInCategory('weather'), $statuses),
            aiStatus: $this->deriveBlockStatus($this->computationsInCategory('ai_analysis'), $statuses),
            stages: array_map($this->serializeStage(...), $stages),
        );
    }

    /**
     * Lists the {@see ComputationName} cases belonging to a progress category,
     * so the block-status derivation stays aligned with {@see ComputationName::category()}
     * instead of hardcoding the WEATHER/WIND or STAGE_AI_ANALYSIS/TRIP_AI_OVERVIEW pairs.
     *
     * @return list<ComputationName>
     */
    private function computationsInCategory(string $category): array
    {
        return array_values(array_filter(
            ComputationName::cases(),
            static fn (ComputationName $c): bool => $c->category() === $category,
        ));
    }

    /**
     * Aggregates the tracked statuses of a block's computations into a single label.
     *
     * Mirrors {@see TripCollectionProvider::computeStatus()} (30-min TTL cache that
     * can return null). Deterministic rule, evaluated against the block's
     * computations actually present in `$statuses`:
     *   - `$statuses === null`                  → null (nothing tracked, e.g. expired TTL)
     *   - no block computation present at all    → null (front falls back to data presence)
     *   - at least one `pending` or `running`    → 'running'
     *   - all present are `done`                 → 'done'
     *   - all present are terminal, ≥1 `failed`,
     *     0 `done`                               → 'failed'
     *
     * @param list<ComputationName>      $computationNames
     * @param array<string, string>|null $statuses
     */
    private function deriveBlockStatus(array $computationNames, ?array $statuses): ?string
    {
        if (null === $statuses) {
            return null;
        }

        $present = [];
        foreach ($computationNames as $name) {
            if (isset($statuses[$name->value])) {
                $present[] = $statuses[$name->value];
            }
        }

        if ([] === $present) {
            return null;
        }

        $hasDone = false;
        $hasFailed = false;
        foreach ($present as $status) {
            if ('pending' === $status || 'running' === $status) {
                return 'running';
            }

            if ('done' === $status) {
                $hasDone = true;
            } elseif ('failed' === $status) {
                $hasFailed = true;
            }
        }

        // All present statuses are terminal here (no pending/running returned above).
        if ($hasFailed && !$hasDone) {
            return 'failed';
        }

        return 'done';
    }

    /**
     * Converts a Stage DTO to the JSON shape the frontend Zustand store expects.
     *
     * @return array<string, mixed>
     */
    private function serializeStage(Stage $stage): array
    {
        return [
            'dayNumber' => $stage->dayNumber,
            'distance' => $stage->distance,
            'elevation' => $stage->elevation,
            'elevationLoss' => $stage->elevationLoss,
            'startPoint' => $this->serializeCoord($stage->startPoint),
            'endPoint' => $this->serializeCoord($stage->endPoint),
            'geometry' => array_map($this->serializeCoord(...), $stage->geometry),
            'label' => $stage->label,
            'isRestDay' => $stage->isRestDay,
            'onCycleNetwork' => $stage->onCycleNetwork,
            'weather' => $stage->weather instanceof WeatherForecast ? $this->serializeWeather($stage->weather) : null,
            'alerts' => array_map($this->serializeAlert(...), $stage->alerts),
            'pois' => array_map($this->serializePoi(...), $stage->pois),
            'accommodations' => array_map($this->serializeAccommodation(...), $stage->accommodations),
            'selectedAccommodation' => $stage->selectedAccommodation instanceof Accommodation
                ? $this->serializeAccommodation($stage->selectedAccommodation)
                : null,
        ];
    }

    /**
     * @return array{lat: float, lon: float, ele: float}
     */
    private function serializeCoord(Coordinate $coord): array
    {
        return ['lat' => $coord->lat, 'lon' => $coord->lon, 'ele' => $coord->ele];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWeather(WeatherForecast $w): array
    {
        return [
            'icon' => $w->icon,
            'description' => $w->description,
            'tempMin' => $w->tempMin,
            'tempMax' => $w->tempMax,
            'windSpeed' => $w->windSpeed,
            'windDirection' => $w->windDirection,
            'precipitationProbability' => $w->precipitationProbability,
            'humidity' => $w->humidity,
            'comfortIndex' => $w->comfortIndex,
            'relativeWindDirection' => $w->relativeWindDirection,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAlert(Alert $alert): array
    {
        return [
            'type' => $alert->type->value,
            'message' => $alert->message,
            'lat' => $alert->lat,
            'lon' => $alert->lon,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePoi(PointOfInterest $poi): array
    {
        return [
            'name' => $poi->name,
            'category' => $poi->category,
            'lat' => $poi->lat,
            'lon' => $poi->lon,
            'distanceFromStart' => $poi->distanceFromStart,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccommodation(Accommodation $acc): array
    {
        return [
            'name' => $acc->name,
            'type' => $acc->type,
            'lat' => $acc->lat,
            'lon' => $acc->lon,
            'estimatedPriceMin' => $acc->estimatedPriceMin,
            'estimatedPriceMax' => $acc->estimatedPriceMax,
            'isExactPrice' => $acc->isExactPrice,
            'url' => $acc->url,
            'possibleClosed' => $acc->possibleClosed,
            'distanceToEndPoint' => $acc->distanceToEndPoint,
        ];
    }
}
