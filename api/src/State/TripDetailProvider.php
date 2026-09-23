<?php

declare(strict_types=1);

namespace App\State;

use App\Enum\ComputationStatus;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Concurrency\TripVersionEtag;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripDetail;
use App\Alert\AlertRenderer;
use App\Alert\ReaderLocale;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mapper\EventArrayMapper;
use App\Enum\ComputationName;
use App\Enum\WeatherAvailability;
use App\Repository\DoctrineTripRequestRepository;
use App\Weather\WeatherForecastSerializer;
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
        private WeatherForecastSerializer $weatherSerializer,
        private EventArrayMapper $eventMapper,
        private AlertRenderer $alertRenderer,
        private ReaderLocale $readerLocale,
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

        // The version the body is built from, advertised as the ETag the client pins with
        // If-Match on its next edit. Read here rather than in the response listener, which
        // would tag the body with whatever version won a race after it was assembled.
        TripVersionEtag::stamp($context, $request->version);

        $statuses = $this->computationTracker->getStatuses($id);

        // Read once: the stages need it too, to tell a forecast that came back empty from one
        // that has not been fetched yet.
        $weatherStatus = $this->deriveBlockStatus($this->computationsInCategory('weather'), $statuses);

        // Whoever is reading, in their own language (ADR-069). Anonymous on /s/{shortCode}:
        // nobody chose a language, so the trip owner's stands in.
        $locale = $this->readerLocale->or($this->tripStateManager->getLocale($id) ?? 'en');

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
            status: $request->status,
            weatherStatus: $weatherStatus,
            categoryStatus: $this->deriveCategoryStatuses($statuses),
            stages: array_map(
                fn (Stage $stage): array => $this->serializeStage($stage, $locale, $request->startDate, $weatherStatus),
                $stages,
            ),
        );
    }

    /**
     * Lists the {@see ComputationName} cases belonging to a progress category,
     * so the block-status derivation stays aligned with {@see ComputationName::category()}
     * instead of hardcoding the WEATHER/WIND pair.
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
     * Where each family of enrichments stands.
     *
     * The per-block rule already existed and was already generic; it was simply only ever
     * called for the weather, leaving a client unable to tell a terrain scan that had failed
     * from one still running (ADR-072). A category with nothing tracked is left out rather
     * than reported as an outcome.
     *
     * @param array<string, string>|null $statuses
     *
     * @return array<string, string>
     */
    private function deriveCategoryStatuses(?array $statuses): array
    {
        $byCategory = [];
        foreach (ComputationName::cases() as $computation) {
            $category = $computation->category();
            if (isset($byCategory[$category])) {
                continue;
            }

            $status = $this->deriveBlockStatus($this->computationsInCategory($category), $statuses);
            if (null !== $status) {
                $byCategory[$category] = $status;
            }
        }

        return $byCategory;
    }

    /**
     * Aggregates the tracked statuses of a block's computations into a single label.
     *
     * Not to be confused with {@see TripCollectionProvider::computeStatus()}: the rules
     * rhyme, but the vocabularies do not overlap at all — this one answers
     * running/done/failed/superseded, that one draft/analyzing/analyzed/failed.
     *
     * Deterministic rule, evaluated against the block's computations actually present in
     * `$statuses`:
     *   - `$statuses === null`                  → null (nothing tracked for this trip)
     *   - no block computation present at all    → null (front falls back to data presence)
     *   - at least one `pending` or `running`    → 'running'
     *   - all terminal, ≥1 `done`                → 'done'
     *   - all terminal, 0 `done`, ≥1 `failed`    → 'failed'
     *   - all terminal, only `superseded`        → 'superseded'
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
            if (!ComputationStatus::tryFrom($status)?->isSettled()) {
                return ComputationStatus::RUNNING->value;
            }

            if (ComputationStatus::DONE->value === $status) {
                $hasDone = true;
            } elseif (ComputationStatus::FAILED->value === $status) {
                $hasFailed = true;
            }
        }

        // All present statuses are terminal here (no pending/running returned above).
        if ($hasDone) {
            return ComputationStatus::DONE->value;
        }

        if ($hasFailed) {
            return ComputationStatus::FAILED->value;
        }

        // Nothing succeeded and nothing failed: every computation in this block was abandoned
        // when the trip moved past it (ADR-073). Not a failure — there is nothing to retry and
        // nothing broke.
        return ComputationStatus::SUPERSEDED->value;
    }

    /**
     * Why this stage has no forecast, or null when the question does not arise (ADR-072).
     *
     * Derived here rather than stored: "too far ahead" is a statement about today, so a stored
     * answer would rot.
     *
     * `past` and `beyond_horizon` are facts about the calendar — true whether or not the
     * computation has run — so they are answered straight away. `unavailable` is a claim about
     * the computation ("it ran and came back with nothing, a recompute may help"), so it is
     * withheld until the weather block has settled: stages exist from the ROUTE computation
     * onwards, long before WEATHER completes, and calling that `unavailable` would tell a
     * reader to retry work that is still in flight. Until then the stage simply has no
     * forecast *yet*, which is what `weatherStatus` says.
     */
    private function weatherAvailability(Stage $stage, ?\DateTimeImmutable $startDate, ?string $weatherStatus): ?string
    {
        if ($stage->weather instanceof WeatherForecast) {
            return null;
        }

        $availability = WeatherAvailability::forStage(
            $startDate?->modify(\sprintf('+%d days', $stage->dayNumber - 1)),
            // UTC, like FetchWeatherHandler: stage dates are normalized to UTC midnight, and a
            // local `today` would put the horizon a day off for a stage sitting exactly on it.
            new \DateTimeImmutable('today', new \DateTimeZone('UTC')),
        );

        if (WeatherAvailability::UNAVAILABLE === $availability && !\in_array($weatherStatus, [ComputationStatus::DONE->value, ComputationStatus::FAILED->value], true)) {
            return null;
        }

        return $availability?->value;
    }

    /**
     * Converts a Stage DTO to the JSON shape the frontend Zustand store expects.
     *
     * @return array<string, mixed>
     */
    private function serializeStage(Stage $stage, string $locale, ?\DateTimeImmutable $startDate, ?string $weatherStatus): array
    {
        return [
            // Emitted but not yet contractual: see StagePayloadMapper::toPayload().
            'stageId' => $stage->id,
            'dayNumber' => $stage->dayNumber,
            'distance' => $stage->distance,
            'elevation' => $stage->elevation,
            'elevationLoss' => $stage->elevationLoss,
            'startPoint' => $this->serializeCoord($stage->startPoint),
            'endPoint' => $this->serializeCoord($stage->endPoint),
            // Geometry is split off to GET /trips/{id}/route (ADR-057): it is the only
            // O(points) per-stage field, and the roadbook summary never renders it.
            'label' => $stage->label,
            'startLabel' => $stage->startLabel,
            'endLabel' => $stage->endLabel,
            'isRestDay' => $stage->isRestDay,
            'onCycleNetwork' => $stage->onCycleNetwork,
            'weather' => $stage->weather instanceof WeatherForecast ? $this->weatherSerializer->toArray($stage->weather) : null,
            'weatherAvailability' => $this->weatherAvailability($stage, $startDate, $weatherStatus),
            // Passed through as the producer wrote it, `group` included: normalising here is
            // what used to drop the richer fields some producers emit (ADR-068).
            'alerts' => $this->alertRenderer->render($stage->alerts, $stage->dayNumber, $locale),
            // Persisted since ADR-068, and served here for the same reason the alerts are:
            // an anonymous visitor to /s/{shortCode} never receives SSE, so a payload the
            // producer only published is a payload they never see.
            'events' => array_map($this->eventMapper->toArray(...), $stage->events),
            'supplyTimeline' => $stage->supplyTimeline,
            'resupply' => $this->serializeResupply($stage->resupply),
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
    private function serializeResupply(?Resupply $resupply): array
    {
        return ($resupply ?? new Resupply())->map($this->serializePoi(...));
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
            'osmType' => $poi->osmType,
            'osmId' => $poi->osmId,
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
            // Same enrichment fields as StagePayloadMapper (issues #870, #873), so a
            // reload and the anonymous shared view are as detailed as the live SSE.
            'source' => $acc->source,
            'description' => $acc->description,
            'imageUrl' => $acc->imageUrl,
            'wikipediaUrl' => $acc->wikipediaUrl,
            'openingHours' => $acc->openingHours,
            'phone' => $acc->phone,
            'osmType' => $acc->osmType,
            'osmId' => $acc->osmId,
        ];
    }
}
