<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Alert\AlertRenderer;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Mapper\StageArrayMapper;

/**
 * Centralizes the wire-format serialization of {@see Stage} instances for Mercure events.
 *
 * Both `trip_ready` (Mode 1 — full payload) and `stage_updated` (Mode 2 — per-stage update)
 * share the same stage-level shape. Keeping the mapping in one place avoids silent drift
 * between the two publishers and mirrors the frontend `StagePayload` type.
 */
final readonly class StagePayloadMapper
{
    public function __construct(
        private StageArrayMapper $stageMapper,
        private AlertRenderer $alertRenderer,
    ) {
    }

    /**
     * Serialises a single stage to the wire format expected by the frontend Mercure types.
     *
     * @return array<string, mixed>
     */
    public function toPayload(Stage $stage, string $locale): array
    {
        return [
            // Emitted but not yet consumed: the clients still address stages by position.
            // Landing it first means the identities can be seen to be stable across edits
            // before anything is hung off them (ADR-066).
            'stageId' => $stage->id,
            'dayNumber' => $stage->dayNumber,
            'distance' => round($stage->distance, 1),
            'elevation' => (int) $stage->elevation,
            'elevationLoss' => (int) $stage->elevationLoss,
            'startPoint' => $this->stageMapper->coordinate($stage->startPoint),
            'endPoint' => $this->stageMapper->coordinate($stage->endPoint),
            'label' => $stage->label,
            'isRestDay' => $stage->isRestDay,
            'geometry' => array_map($this->stageMapper->coordinate(...), $stage->geometry),
            'weather' => $this->stageMapper->weatherForClient($stage->weather),
            // Already in wire shape, each tagged with its group: the producers build it
            // once and hand the same array to the database and to Mercure (ADR-068).
            //
            // Rendered in the trip's language, because that is the only reader here: an
            // anonymous visitor gets no SSE, so the audience for this payload is the owner
            // whose account locale the trip carries (ADR-069).
            'alerts' => $this->alertRenderer->render($stage->alerts, $stage->dayNumber, $locale),
            'resupply' => $this->stageMapper->resupplyForClient($stage->resupply),
            'accommodations' => array_map($this->stageMapper->accommodation(...), $stage->accommodations),
            'selectedAccommodation' => $stage->selectedAccommodation instanceof Accommodation
                ? $this->stageMapper->accommodation($stage->selectedAccommodation)
                : null,
            'events' => array_map($this->stageMapper->event(...), $stage->events),
        ];
    }

    /**
     * Serialises a list of stages.
     *
     * @param list<Stage> $stages
     *
     * @return list<array<string, mixed>>
     */
    public function toPayloadList(array $stages, string $locale): array
    {
        return array_map(fn (Stage $stage): array => $this->toPayload($stage, $locale), $stages);
    }

    /**
     * Serialises a single alert, including its contextual action when the kind is
     * actually wired in the frontend (see {@see AlertAction::toDeliverablePayload()}).
     *
     * @return array<string, mixed>
     */
    public function alertToPayload(Alert $alert): array
    {
        $payload = [
            'code' => $alert->code?->value,
            'type' => $alert->type->value,
            'messageKey' => $alert->messageKey,
            'parameters' => $alert->parameters,
            'parameterFormats' => $alert->parameterFormats,
            'lat' => $alert->lat,
            'lon' => $alert->lon,
        ];

        $action = $alert->action?->toDeliverablePayload();
        if (null !== $action) {
            $payload['action'] = $action;
        }

        return $payload;
    }
}
