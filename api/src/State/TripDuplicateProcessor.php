<?php

declare(strict_types=1);

namespace App\State;

use Throwable;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\Stage;
use App\Enum\ComputationName;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Duplicates an existing trip (deep clone: TripRequest + all Stage entities).
 *
 * @implements ProcessorInterface<TripRequest, Trip>
 */
final readonly class TripDuplicateProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripRepository,
        private EntityManagerInterface $entityManager,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
    ) {
    }

    /**
     * @param TripRequest        $data         The source TripRequest, resolved by {@see TripRequestProvider}
     * @param Post               $operation
     * @param array{id?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        \assert($data instanceof TripRequest);
        $source = $data;
        $sourceId = $uriVariables['id'] ?? '';

        // Deep-clone the TripRequest entity with a new UUID
        $duplicate = new TripRequest();
        $duplicate->sourceUrl = $source->sourceUrl;
        $duplicate->startDate = $source->startDate;
        $duplicate->endDate = $source->endDate;
        $duplicate->fatigueFactor = $source->fatigueFactor;
        $duplicate->elevationPenalty = $source->elevationPenalty;
        $duplicate->ebikeMode = $source->ebikeMode;
        $duplicate->departureHour = $source->departureHour;
        $duplicate->maxDistancePerDay = $source->maxDistancePerDay;
        $duplicate->averageSpeed = $source->averageSpeed;
        $duplicate->enabledAccommodationTypes = $source->enabledAccommodationTypes;
        $duplicate->title = $source->title;
        $duplicate->sourceType = $source->sourceType;
        $duplicate->locale = $source->locale;

        $newTripId = $duplicate->id;
        \assert($newTripId instanceof Uuid);
        $newTripIdString = $newTripId->toRfc4122();

        $this->entityManager->persist($duplicate);

        // Deep-clone all stages, preserving computed data
        foreach ($source->stages as $sourceStage) {
            $clonedStage = $this->cloneStage($sourceStage, $duplicate);
            $duplicate->addStage($clonedStage);
            $this->entityManager->persist($clonedStage);
        }

        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->flush();

            // Copy transient Redis data from source trip to duplicate
            $this->copyTransientData($sourceId, $newTripIdString, $duplicate);

            // Initialize computation tracker as done (all computations are inherited from source)
            $computations = ComputationName::pipeline();
            $this->computationTracker->initializeComputations($newTripIdString, $computations);

            foreach ($computations as $computation) {
                $this->computationTracker->markDone($newTripIdString, $computation);
            }

            $this->generationTracker->initialize($newTripIdString);
            $this->entityManager->commit();
        } catch (Throwable $throwable) {
            $this->entityManager->rollback();
            throw $throwable;
        }

        $statuses = $this->computationTracker->getStatuses($newTripIdString) ?? [];

        return new Trip(
            id: $newTripIdString,
            computationStatus: $statuses,
        );
    }

    private function cloneStage(Stage $source, TripRequest $newTrip): Stage
    {
        $clone = new Stage($newTrip);
        $clone->setPosition($source->getPosition());
        $clone->setDayNumber($source->getDayNumber());
        $clone->setDistance($source->getDistance());
        $clone->setElevation($source->getElevation());
        $clone->setElevationLoss($source->getElevationLoss());
        $clone->setStartLat($source->getStartLat());
        $clone->setStartLon($source->getStartLon());
        $clone->setStartEle($source->getStartEle());
        $clone->setEndLat($source->getEndLat());
        $clone->setEndLon($source->getEndLon());
        $clone->setEndEle($source->getEndEle());
        $clone->setGeometry($source->getGeometry());
        $clone->setLabel($source->getLabel());
        $clone->setIsRestDay($source->isRestDay());
        $clone->setWeather($source->getWeather());
        $clone->setAlerts($source->getAlerts());
        $clone->setPois($source->getPois());
        $clone->setAccommodations($source->getAccommodations());
        $clone->setSelectedAccommodation($source->getSelectedAccommodation());

        return $clone;
    }

    private function copyTransientData(string $sourceId, string $newTripId, TripRequest $duplicate): void
    {
        // Persist the duplicate TripRequest in Redis so that subsequent operations
        // (getRequest) can find it and do not return 404.
        $this->tripRepository->storeRequest($newTripId, $duplicate);

        $rawPoints = $this->tripRepository->getRawPoints($sourceId);
        if (null !== $rawPoints) {
            $this->tripRepository->storeRawPoints($newTripId, $rawPoints);
        }

        $decimatedPoints = $this->tripRepository->getDecimatedPoints($sourceId);
        if (null !== $decimatedPoints) {
            $this->tripRepository->storeDecimatedPoints($newTripId, $decimatedPoints);
        }

        $tracksData = $this->tripRepository->getTracksData($sourceId);
        if (null !== $tracksData) {
            $this->tripRepository->storeTracksData($newTripId, $tracksData);
        }

        $title = $this->tripRepository->getTitle($sourceId);
        if (null !== $title) {
            $this->tripRepository->storeTitle($newTripId, $title);
        }

        $sourceType = $this->tripRepository->getSourceType($sourceId);
        if (null !== $sourceType) {
            $this->tripRepository->storeSourceType($newTripId, $sourceType);
        }

        $locale = $this->tripRepository->getLocale($sourceId);
        if (null !== $locale) {
            $this->tripRepository->storeLocale($newTripId, $locale);
        }
    }
}
