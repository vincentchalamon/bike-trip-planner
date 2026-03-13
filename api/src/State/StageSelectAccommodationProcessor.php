<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\StageResponse;
use App\ApiResource\StageSelectAccommodationRequest;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

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
        private ObjectMapperInterface $objectMapper,
    ) {
    }

    /**
     * @param StageSelectAccommodationRequest     $data
     * @param Patch                               $operation
     * @param array{tripId?: string, index?: int} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $index = \is_numeric($uriVariables['index'] ?? null) ? (int) $uriVariables['index'] : 0;

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

        $stage = $stages[$index];

        // Deselect: clear selected accommodation and trigger a new accommodation scan
        if (null === $data->selectedAccommodationLat || null === $data->selectedAccommodationLon) {
            $stage->selectedAccommodation = null;
            $stages[$index] = $stage;
            $this->tripStateManager->storeStages($tripId, $stages);
            // Note: endPoint intentionally not reverted — accommodation coords serve as
            // stage boundary until Valhalla (ADR-017) provides proper re-route.
            $request = $this->tripStateManager->getRequest($tripId);
            $enabledAccommodationTypes = $request?->enabledAccommodationTypes ?? ['camp_site', 'hostel', 'alpine_hut', 'chalet', 'guest_house', 'motel', 'hotel'];
            $this->messageBus->dispatch(new ScanAccommodations($tripId, enabledAccommodationTypes: $enabledAccommodationTypes));
            $affectedDeselect = isset($stages[$index + 1]) ? [$index, $index + 1] : [$index];
            $this->messageBus->dispatch(new RecalculateStages($tripId, $affectedDeselect, skipAccommodationScan: true));

            return $this->objectMapper->map($stage, StageResponse::class);
        }

        $lat = $data->selectedAccommodationLat;
        $lon = $data->selectedAccommodationLon;

        $selected = null;
        foreach ($stage->accommodations as $accommodation) {
            if (abs($accommodation->lat - $lat) < 1e-6 && abs($accommodation->lon - $lon) < 1e-6) {
                $selected = $accommodation;
                break;
            }
        }

        if (null === $selected) {
            throw new UnprocessableEntityHttpException(\sprintf('Accommodation at coordinates (%F, %F) not found for stage %d.', $lat, $lon, $index));
        }

        // Keep only the selected accommodation (remove others)
        $stage->accommodations = [$selected];
        $stage->selectedAccommodation = $selected;

        // Update stage endPoint to the accommodation coordinates (marker only)
        // Distance and geometry are intentionally preserved from the original GPX route
        $stage->endPoint = new \App\ApiResource\Model\Coordinate($selected->lat, $selected->lon);

        $stages[$index] = $stage;

        // Update the next stage startPoint to the same accommodation coordinates
        if (isset($stages[$index + 1])) {
            $nextStage = $stages[$index + 1];
            $nextStage->startPoint = $stage->endPoint;
            $stages[$index + 1] = $nextStage;
        }

        $this->tripStateManager->storeStages($tripId, $stages);

        // Trigger recalculation for affected stages
        $affectedIndices = [$index];
        if (isset($stages[$index + 1])) {
            $affectedIndices[] = $index + 1;
        }

        $this->messageBus->dispatch(new RecalculateStages($tripId, $affectedIndices, skipAccommodationScan: true));

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId));
            $this->messageBus->dispatch(new CheckCalendar($tripId));
        }

        return $this->objectMapper->map($stage, StageResponse::class);
    }
}
