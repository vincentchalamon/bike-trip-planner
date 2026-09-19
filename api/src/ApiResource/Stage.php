<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\State\RestDayInsertProcessor;
use App\State\StageAddManualAccommodationProcessor;
use App\State\StageCreateProcessor;
use App\State\StageDeleteProcessor;
use App\State\StageDetailProvider;
use App\State\StageMoveProcessor;
use App\State\StagePoiWaypointProcessor;
use App\State\StageProvider;
use App\State\StageSelectAccommodationProcessor;
use App\State\StageUpdateProcessor;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    shortName: 'Stage',
    operations: [
        new Get(
            // Dedicated export sub-route: the canonical '/trips/{tripId}/stages/{stageId}'
            // path is the StageResponse NotExposed IRI (json-ld). Sharing it made the
            // NotExposedAction controller shadow this gpx/fit download, so the
            // authenticated download 404'd with "This route does not aim to be called"
            // (recette #649). A distinct path avoids the collision.
            uriTemplate: '/trips/{tripId}/stages/{stageId}/export{._format}',
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
                'fit' => ['application/vnd.ant.fit'],
            ],
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            openapi: new Operation(summary: 'Download a stage as GPX or FIT file.'),
            security: "is_granted('TRIP_VIEW', tripId)",
            provider: StageProvider::class,
        ),
        new Get(
            // On-demand full stage detail (ADR-057), on a distinct sub-route for the
            // same reason as the export above: the plain '/stages/{stageId}' path is the
            // StageResponse NotExposed IRI and would be shadowed by NotExposedAction.
            uriTemplate: '/trips/{tripId}/stages/{stageId}/detail{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            openapi: new Operation(summary: 'Load one stage in full (geometry, resupply, accommodations, events, classified alerts, weather).'),
            security: "is_granted('TRIP_VIEW', tripId)",
            output: StageResponse::class,
            provider: StageDetailProvider::class,
        ),
        new Post(
            uriTemplate: '/trips/{tripId}/stages{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Add a manual stage at a given position.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: StageRequest::class,
            output: StageResponse::class,
            processor: StageCreateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{tripId}/stages/{stageId}{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Update stage data (start/end points, etc.).'),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: StageRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StageUpdateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{tripId}/stages/{stageId}/move{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Move a stage to a new position.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: StageRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StageMoveProcessor::class,
        ),
        new Delete(
            uriTemplate: '/trips/{tripId}/stages/{stageId}{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Delete a stage (merge with adjacent).'),
            security: "is_granted('TRIP_EDIT', tripId)",
            provider: StageProvider::class,
            processor: StageDeleteProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{tripId}/stages/{stageId}/rest-day{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Insert a rest day after a given stage. The next stage startPoint stays identical; dates shift by one day.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: false,
            output: StageResponse::class,
            processor: RestDayInsertProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{tripId}/stages/{stageId}/accommodation{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Select or deselect an accommodation for a stage. Selecting updates stage endPoint and next stage startPoint.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: StageSelectAccommodationRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StageSelectAccommodationProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{tripId}/stages/{stageId}/accommodations/manual{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(
                responses: [
                    202 => new Response(description: 'Manual accommodation added and selected; recalculation dispatched.'),
                    404 => new Response(description: 'Trip or stage not found.'),
                    422 => new Response(description: 'The address could not be geocoded (not found or ambiguous); nothing is persisted.'),
                ],
                summary: 'Add a manually-entered accommodation to a stage. The address is geocoded, the accommodation becomes the selected one, and the stage endPoint plus the next stage startPoint move to it.',
            ),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: StageManualAccommodationRequest::class,
            output: StageResponse::class,
            processor: StageAddManualAccommodationProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{tripId}/stages/{stageId}/poi-waypoint{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Add a cultural POI as a waypoint to a stage, triggering async route recalculation via Valhalla.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            input: StagePoiWaypointRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StagePoiWaypointProcessor::class,
        ),
    ],
)]
final class Stage
{
    /**
     * Stable identity of the stage, carried by the DTO so it survives every write:
     * the repository reconciles the persisted rows against these identifiers instead
     * of deleting and re-inserting the collection (ADR-066).
     *
     * Server-owned, never writable: an identifier coming from a request body would let
     * a client dictate which row an edit lands on. Reconciliation only ever matches
     * within the stages of the trip being written.
     *
     * Stable *within a pacing generation*: it survives an insertion, a move, a deletion,
     * a rest day and a distance edit, but a regeneration produces new stages, hence new
     * identifiers.
     */
    #[ApiProperty(writable: false)]
    public string $id;

    public ?WeatherForecast $weather = null;

    /** @var Alert[] */
    public array $alerts = [];

    public ?Resupply $resupply = null;

    /** @var Accommodation[] */
    public array $accommodations = [];

    public ?Accommodation $selectedAccommodation = null;

    /**
     * Fraction (0..1) of the stage line that follows a signed cycle route,
     * persisted at stage-store time and read back here (issue #775).
     *
     * Server-computed from PostGIS: readable (the frontend consumes it) but never
     * writable, so a client PATCH/PUT on a Stage cannot overwrite the computed
     * value (review on #787).
     */
    #[ApiProperty(writable: false)]
    public float $onCycleNetwork = 0.0;

    /**
     * Reverse-geocoded city names for the stage endpoints (recette #649, #3c/#9),
     * resolved server-side and persisted so the anonymous shared view and a
     * reloaded trip render city names instead of raw GPS coordinates. Readable
     * (the frontend consumes them) but never writable.
     */
    #[ApiProperty(writable: false)]
    public ?string $startLabel = null;

    #[ApiProperty(writable: false)]
    public ?string $endLabel = null;

    /** @var Event[] */
    public array $events = [];

    /**
     * @param list<Coordinate> $geometry
     */
    public function __construct(
        public string $tripId,
        public int $dayNumber,
        public float $distance,
        public float $elevation,
        public Coordinate $startPoint,
        public Coordinate $endPoint,
        public array $geometry = [],
        public ?string $label = null,
        public float $elevationLoss = 0.0,
        public bool $isRestDay = false,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
    }

    public function addAlert(Alert $alert): void
    {
        $this->alerts[] = $alert;
    }

    public function addAccommodation(Accommodation $accommodation): void
    {
        $this->accommodations[] = $accommodation;
    }

    public function addEvent(Event $event): void
    {
        $this->events[] = $event;
    }
}
