<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use App\ApiResource\Model\Coordinate;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\Concurrency\IfMatch;
use App\Concurrency\TripVersionEtag;
use App\ApiResource\Stage;
use App\ApiResource\StageResponse;
use App\ApiResource\StageSelectAccommodationRequest;
use App\Mapper\StageResponseMapper;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Repository\StageWriteResult;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles selecting or deselecting an accommodation for a stage.
 *
 * When an accommodation is selected:
 * - All other accommodations for the stage are removed (only the selected one is kept).
 * - The stage endPoint is updated to the accommodation coordinates.
 * - The next stage startPoint is updated to the same accommodation coordinates.
 * - A route recalculation is triggered for the current stage and next stage.
 *
 * When an accommodation is deselected (selectedAccommodationIndex = null):
 * - selectedAccommodation is cleared on the stage.
 * - A new accommodation search is triggered to repopulate options.
 *
 * Note: Selecting an accommodation updates the stage endPoint marker but does NOT
 * change the stage distance or geometry — those reflect the actual GPX route and
 * must not be overwritten with a straight-line approximation. Route recalculation
 * via Valhalla (ADR-017) is not yet implemented.
 *
 * @implements ProcessorInterface<StageSelectAccommodationRequest, StageResponse>
 */
final readonly class StageSelectAccommodationProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private StageResponseMapper $stageResponseMapper,
        private TripLocker $tripLocker,
        private StageLocator $stageLocator,
    ) {
    }

    /**
     * @param StageSelectAccommodationRequest          $data
     * @param Patch                                    $operation
     * @param array{tripId?: string, stageId?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $stageId = $uriVariables['stageId'] ?? '';
        $index = 0;

        $request = $this->tripStateManager->getRequest($tripId);
        \assert($request instanceof TripRequest);
        $this->tripLocker->assertNotLocked($request);

        $stage = null;
        $isDeselect = null === $data->selectedAccommodationLat || null === $data->selectedAccommodationLon;

        // Read, edit and write as one unit: an accommodation scan running concurrently
        // writes the very column this edits, and the snapshot read here would revert it.
        $write = $this->tripStateManager->mutateStages($tripId, function (array $stages) use ($data, $stageId, $isDeselect, &$index, &$stage): array {
            $index = $this->stageLocator->indexOf($stages, $stageId);

            $stage = $stages[$index];

            // Deselect: clear selected accommodation; a new scan is dispatched below.
            // Note: endPoint intentionally not reverted — accommodation coords serve as
            // stage boundary until Valhalla (ADR-017) provides proper re-route.
            if ($isDeselect) {
                $stage->selectedAccommodation = null;
                $stages[$index] = $stage;

                return array_values($stages);
            }

            $lat = $data->selectedAccommodationLat;
            $lon = $data->selectedAccommodationLon;
            \assert(null !== $lat && null !== $lon);

            $selected = null;
            foreach ($stage->accommodations as $accommodation) {
                if (abs($accommodation->lat - $lat) < 1e-6 && abs($accommodation->lon - $lon) < 1e-6) {
                    $selected = $accommodation;
                    break;
                }
            }

            // Fallback: the accommodation list may have been refreshed by a concurrent
            // scan while the user was looking at the old list. Check selectedAccommodation
            // (set by a previous selection that survived the re-scan) as a last resort.
            if (null === $selected && null !== $stage->selectedAccommodation) {
                $acc = $stage->selectedAccommodation;
                if (abs($acc->lat - $lat) < 1e-6 && abs($acc->lon - $lon) < 1e-6) {
                    $selected = $acc;
                }
            }

            if (null === $selected) {
                // The frontend is showing stale accommodation data — a concurrent scan
                // replaced the list. Return 409 so the frontend can refresh and retry.
                throw new ConflictHttpException(\sprintf('Accommodation at (%F, %F) is no longer in the current list for stage %d. Accommodation data may have been refreshed; please retry.', $lat, $lon, $index));
            }

            // Keep only the selected accommodation (remove others)
            $stage->accommodations = [$selected];
            $stage->selectedAccommodation = $selected;

            // Update stage endPoint to the accommodation coordinates (marker only)
            // Distance and geometry are intentionally preserved from the original GPX route
            $stage->endPoint = new Coordinate($selected->lat, $selected->lon);

            $stages[$index] = $stage;

            // Update the next stage startPoint to the same accommodation coordinates
            if (isset($stages[$index + 1])) {
                $nextStage = $stages[$index + 1];
                $nextStage->startPoint = $stage->endPoint;
                $stages[$index + 1] = $nextStage;
            }

            return array_values($stages);
        }, IfMatch::expectedVersion($context));

        TripVersionEtag::stamp($context, $write?->version);

        // The trip was asserted to exist above, so the write happened.
        \assert($write instanceof StageWriteResult);

        $stages = $write->stages;
        // The generation comes back from inside the locked write. Re-reading it here would
        // hand us whichever version won the race after the lock was released.
        $generation = $write->version;

        \assert($stage instanceof Stage);

        if ($isDeselect) {
            $this->messageBus->dispatch(new ScanAccommodations($tripId, stageId: $stage->id, enabledAccommodationTypes: $request->enabledAccommodationTypes, generation: $generation));
            $affectedDeselect = isset($stages[$index + 1]) ? [$stage->id, $stages[$index + 1]->id] : [$stage->id];
            $this->messageBus->dispatch(new RecalculateStages($tripId, $affectedDeselect, skipAccommodationScan: true, generation: $generation));

            return $this->stageResponseMapper->map($stage);
        }

        // Trigger recalculation for affected stages
        $affected = [$stage->id];
        if (isset($stages[$index + 1])) {
            $affected[] = $stages[$index + 1]->id;
        }

        $this->messageBus->dispatch(new RecalculateStages($tripId, $affected, skipAccommodationScan: true, generation: $generation));

        if ($request->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->stageResponseMapper->map($stage);
    }
}
