<?php

declare(strict_types=1);

namespace App\Mercure;

use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;

/**
 * Centralizes the wire-format serialization of {@see Stage} instances for Mercure events.
 *
 * Both `trip_ready` (Mode 1 — full payload) and `stage_updated` (Mode 2 — per-stage update)
 * share the same stage-level shape. Keeping the mapping in one place avoids silent drift
 * between the two publishers and mirrors the frontend `StagePayload` type.
 */
final readonly class StagePayloadMapper
{
    /**
     * Serialises a single stage to the wire format expected by the frontend Mercure types.
     *
     * @return array<string, mixed>
     */
    public function toPayload(Stage $stage): array
    {
        return [
            'dayNumber' => $stage->dayNumber,
            'distance' => round($stage->distance, 1),
            'elevation' => (int) $stage->elevation,
            'elevationLoss' => (int) $stage->elevationLoss,
            'startPoint' => $this->coordinateToPayload($stage->startPoint),
            'endPoint' => $this->coordinateToPayload($stage->endPoint),
            'label' => $stage->label,
            'isRestDay' => $stage->isRestDay,
            'geometry' => array_map(
                $this->coordinateToPayload(...),
                $stage->geometry,
            ),
            'weather' => $stage->weather instanceof WeatherForecast ? $this->weatherToPayload($stage->weather) : null,
            'alerts' => array_map(
                $this->alertToPayload(...),
                $stage->alerts,
            ),
            'pois' => array_map(
                $this->poiToPayload(...),
                $stage->pois,
            ),
            'accommodations' => array_map(
                $this->accommodationToPayload(...),
                $stage->accommodations,
            ),
            'selectedAccommodation' => $stage->selectedAccommodation instanceof Accommodation
                ? $this->accommodationToPayload($stage->selectedAccommodation)
                : null,
            'events' => array_map(
                $this->eventToPayload(...),
                $stage->events,
            ),
        ];
    }

    /**
     * Serialises a list of stages.
     *
     * @param list<Stage> $stages
     *
     * @return list<array<string, mixed>>
     */
    public function toPayloadList(array $stages): array
    {
        return array_map($this->toPayload(...), $stages);
    }

    /** @return array{lat: float, lon: float, ele: float} */
    private function coordinateToPayload(Coordinate $coordinate): array
    {
        return ['lat' => $coordinate->lat, 'lon' => $coordinate->lon, 'ele' => $coordinate->ele];
    }

    /** @return array<string, mixed> */
    private function alertToPayload(Alert $alert): array
    {
        $payload = [
            'type' => $alert->type->value,
            'message' => $alert->message,
            'lat' => $alert->lat,
            'lon' => $alert->lon,
        ];

        if ($alert->action instanceof AlertAction) {
            $payload['action'] = [
                'kind' => $alert->action->kind->value,
                'label' => $alert->action->label,
                'payload' => $alert->action->payload,
            ];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function poiToPayload(PointOfInterest $poi): array
    {
        return [
            'name' => $poi->name,
            'category' => $poi->category,
            'lat' => $poi->lat,
            'lon' => $poi->lon,
            'distanceFromStart' => $poi->distanceFromStart,
        ];
    }

    /** @return array<string, mixed> */
    private function accommodationToPayload(Accommodation $accommodation): array
    {
        return [
            'name' => $accommodation->name,
            'type' => $accommodation->type,
            'lat' => $accommodation->lat,
            'lon' => $accommodation->lon,
            'estimatedPriceMin' => $accommodation->estimatedPriceMin,
            'estimatedPriceMax' => $accommodation->estimatedPriceMax,
            'isExactPrice' => $accommodation->isExactPrice,
            'url' => $accommodation->url,
            'possibleClosed' => $accommodation->possibleClosed,
            'distanceToEndPoint' => $accommodation->distanceToEndPoint,
            'source' => $accommodation->source,
            'description' => $accommodation->description,
            'imageUrl' => $accommodation->imageUrl,
            'wikipediaUrl' => $accommodation->wikipediaUrl,
            'openingHours' => $accommodation->openingHours,
        ];
    }

    /** @return array<string, mixed> */
    private function eventToPayload(Event $event): array
    {
        return [
            'name' => $event->name,
            'type' => $event->type,
            'lat' => $event->lat,
            'lon' => $event->lon,
            'startDate' => $event->startDate->format(\DateTimeInterface::ATOM),
            'endDate' => $event->endDate->format(\DateTimeInterface::ATOM),
            'url' => $event->url,
            'description' => $event->description,
            'priceMin' => $event->priceMin,
            'distanceToEndPoint' => $event->distanceToEndPoint,
            'source' => $event->source,
            'wikidataId' => $event->wikidataId,
            'imageUrl' => $event->imageUrl,
            'wikipediaUrl' => $event->wikipediaUrl,
            'openingHours' => $event->openingHours,
        ];
    }

    /** @return array<string, mixed> */
    private function weatherToPayload(WeatherForecast $weather): array
    {
        return [
            'icon' => $weather->icon,
            'description' => $weather->description,
            'tempMin' => $weather->tempMin,
            'tempMax' => $weather->tempMax,
            'windSpeed' => round($weather->windSpeed, 1),
            'windDirection' => $weather->windDirection,
            'precipitationProbability' => $weather->precipitationProbability,
            'humidity' => $weather->humidity,
            'comfortIndex' => $weather->comfortIndex,
            'relativeWindDirection' => $weather->relativeWindDirection,
        ];
    }
}
