<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\StageManualAccommodationRequest;
use App\ApiResource\Stage;
use App\ApiResource\StageResponse;
use App\ApiResource\TripRequest;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeocoderInterface;
use App\Mapper\StageResponseMapper;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Adds a manually-entered ("hors-app") accommodation to a stage.
 *
 * The address is geocoded (Nominatim) into coordinates; the produced object is a
 * first-class {@see Accommodation} (source "manual", type "other") indistinguishable
 * downstream from a scanned one. It becomes the stage's sole, selected accommodation
 * and the exact same selection side effects run as in {@see StageSelectAccommodationProcessor}:
 * the stage endPoint and the next stage startPoint move to the accommodation, and a
 * recalculation (plus weather/calendar when dated) is dispatched. Nothing is written
 * to the OSM/DataTourisme reference tables — persistence is the stage JSONB only.
 *
 * @implements ProcessorInterface<StageManualAccommodationRequest, StageResponse>
 */
final readonly class StageAddManualAccommodationProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private StageResponseMapper $stageResponseMapper,
        private TripGenerationTrackerInterface $generationTracker,
        private TripLocker $tripLocker,
        private GeocoderInterface $geocoder,
    ) {
    }

    /**
     * @param StageManualAccommodationRequest     $data
     * @param Post                                $operation
     * @param array{tripId?: string, index?: int} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $index = \is_numeric($uriVariables['index'] ?? null) ? (int) $uriVariables['index'] : 0;

        $request = $this->tripStateManager->getRequest($tripId);
        \assert($request instanceof TripRequest);
        $this->tripLocker->assertNotLocked($request);

        // Geocode before mutating anything: a non-resolvable/ambiguous address is a
        // 422 with nothing persisted (acceptance: rien persisté). Kept out of the
        // critical section below — it is a network call, and the lock is not for waiting on.
        $coordinate = $this->geocoder->geocode($data->address);
        if (!$coordinate instanceof Coordinate) {
            throw new UnprocessableEntityHttpException(\sprintf('Address "%s" could not be geocoded. Refine it (add a city or postcode) and try again.', $data->address));
        }

        // Total price maps onto the standard exact-price contract; omitted → no price.
        $price = $data->priceTotal;
        $url = null !== $data->url ? trim($data->url) : '';

        $accommodation = new Accommodation(
            name: $data->name,
            type: 'other',
            lat: $coordinate->lat,
            lon: $coordinate->lon,
            estimatedPriceMin: $price ?? 0.0,
            estimatedPriceMax: $price ?? 0.0,
            isExactPrice: null !== $price,
            url: '' !== $url ? $url : null,
            source: 'manual',
            address: $data->address,
        );

        $stage = null;

        // Read, edit and write as one unit: an accommodation scan running concurrently
        // writes the very column this edits, and the snapshot read here would revert it.
        $stages = $this->tripStateManager->mutateStages($tripId, function (array $stages) use ($index, $accommodation, &$stage): array {
            if (!isset($stages[$index])) {
                throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
            }

            $stage = $stages[$index];

            // Same downstream as selecting a scanned accommodation: keep only this one,
            // mark it selected, move the stage boundary to its coordinates.
            $stage->accommodations = [$accommodation];
            $stage->selectedAccommodation = $accommodation;
            $stage->endPoint = new Coordinate($accommodation->lat, $accommodation->lon);

            $stages[$index] = $stage;

            if (isset($stages[$index + 1])) {
                $nextStage = $stages[$index + 1];
                $nextStage->startPoint = $stage->endPoint;
                $stages[$index + 1] = $nextStage;
            }

            return $stages;
        }) ?? [];

        \assert($stage instanceof Stage);

        $affected = [$stage->id];
        if (isset($stages[$index + 1])) {
            $affected[] = $stages[$index + 1]->id;
        }

        $generation = $this->generationTracker->current($tripId) ?? 1;

        $this->messageBus->dispatch(new RecalculateStages($tripId, $affected, skipAccommodationScan: true, generation: $generation));

        if ($request->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->stageResponseMapper->map($stage);
    }
}
