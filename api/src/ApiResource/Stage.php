<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;
use App\State\StageCreateProcessor;
use App\State\StageDeleteProcessor;
use App\State\StageMoveProcessor;
use App\State\StageProvider;
use App\State\StageUpdateProcessor;
use App\Symfony\ObjectMapper\TripTransformer;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[ApiResource(
    shortName: 'Stage',
    operations: [
        new Post(
            uriTemplate: '/trips/{tripId}/stages{._format}',
            uriVariables: [
                'tripId' => new Link(fromClass: Stage::class),
            ],
            status: 202,
            openapi: new Operation(summary: 'Add a manual stage at a given position.'),
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
            provider: StageProvider::class,
            processor: StageDeleteProcessor::class,
        ),
    ],
)]
#[Map(target: StageResponse::class)]
final class Stage
{
    public ?WeatherForecast $weather = null;

    public ?string $gpxContent = null;

    /** @var Alert[] */
    public array $alerts = [];

    /** @var PointOfInterest[] */
    public array $pois = [];

    /** @var Accommodation[] */
    public array $accommodations = [];

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
