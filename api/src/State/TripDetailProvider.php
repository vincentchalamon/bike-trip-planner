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
            stages: array_map($this->serializeStage(...), $stages),
        );
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
