<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;
use App\State\RestDayInsertProcessor;
use App\State\StageCreateProcessor;
use App\State\StageDeleteProcessor;
use App\State\StageMoveProcessor;
use App\State\StagePoiWaypointProcessor;
use App\State\StageProvider;
use App\State\StageSelectAccommodationProcessor;
use App\State\StageUpdateProcessor;
use App\Symfony\ObjectMapper\TripTransformer;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[ApiResource(
    shortName: 'Stage',
    operations: [
        new Get(
            uriTemplate: '/trips/{tripId}/stages/{index}{._format}',
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
                'fit' => ['application/vnd.ant.fit'],
            ],
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            openapi: new Operation(summary: 'Download a stage as GPX or FIT file.'),
            security: "is_granted('TRIP_VIEW', request.attributes.get('tripId'))",
            provider: StageProvider::class,
        ),
        new Post(
            uriTemplate: '/trips/{tripId}/stages{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Add a manual stage at a given position.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            input: StageRequest::class,
            output: StageResponse::class,
            processor: StageCreateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{tripId}/stages/{index}{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Update stage data (start/end points, etc.).'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            input: StageRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StageUpdateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{tripId}/stages/{index}/move{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Move a stage to a new position.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            input: StageRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StageMoveProcessor::class,
        ),
        new Delete(
            uriTemplate: '/trips/{tripId}/stages/{index}{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Delete a stage (merge with adjacent).'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            provider: StageProvider::class,
            processor: StageDeleteProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{tripId}/stages/{index}/rest-day{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Insert a rest day after a given stage. The next stage startPoint stays identical; dates shift by one day.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            input: false,
            output: StageResponse::class,
            processor: RestDayInsertProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{tripId}/stages/{index}/accommodation{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Select or deselect an accommodation for a stage. Selecting updates stage endPoint and next stage startPoint.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            input: StageSelectAccommodationRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StageSelectAccommodationProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{tripId}/stages/{index}/poi-waypoint{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Add a cultural POI as a waypoint to a stage, triggering async route recalculation via Valhalla.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            input: StagePoiWaypointRequest::class,
            output: StageResponse::class,
            provider: StageProvider::class,
            processor: StagePoiWaypointProcessor::class,
        ),
    ],
)]
#[Map(target: StageResponse::class)]
final class Stage
{
    public ?WeatherForecast $weather = null;

    /** @var Alert[] */
    public array $alerts = [];

    /** @var PointOfInterest[] */
    public array $pois = [];

    /** @var Accommodation[] */
    public array $accommodations = [];

    public ?Accommodation $selectedAccommodation = null;

    /**
     * @param list<Coordinate> $geometry
     */
    public function __construct(
        #[Map(target: 'trip', transform: TripTransformer::class)]
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
    ) {
    }

    public function addAlert(Alert $alert): void
    {
        $this->alerts[] = $alert;
    }

    public function addPoi(PointOfInterest $poi): void
    {
        $this->pois[] = $poi;
    }

    public function addAccommodation(Accommodation $accommodation): void
    {
        $this->accommodations[] = $accommodation;
    }
}
