<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\TripCollectionProvider;
use App\State\TripCreateProcessor;
use App\State\TripDeleteProcessor;
use App\State\TripDoctrineProvider;
use App\State\TripGpxProvider;
use App\State\TripRequestProvider;
use App\State\TripUpdateProcessor;

#[ApiResource(
    shortName: 'Trip',
    operations: [
        new GetCollection(
            uriTemplate: '/trips',
            openapi: new Operation(summary: 'List all trips, paginated and filterable.'),
            paginationEnabled: true,
            paginationItemsPerPage: 20,
            paginationClientItemsPerPage: true,
            output: TripListItem::class,
            provider: TripCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/trips{._format}',
            status: 202,
            validationContext: ['groups' => ['trip_request:create']],
            input: TripRequest::class,
            mercure: true,
            processor: TripCreateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{id}{._format}',
            status: 202,
            input: TripRequest::class,
            mercure: true,
            provider: TripRequestProvider::class,
            processor: TripUpdateProcessor::class,
        ),
        new Get(
            uriTemplate: '/trips/{id}{._format}',
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
            ],
            openapi: new Operation(summary: 'Download the full trip as a single GPX file containing all stages.'),
            provider: TripGpxProvider::class,
        ),
        new Delete(
            uriTemplate: '/trips/{id}',
            openapi: new Operation(summary: 'Delete a trip and all its stages.'),
            provider: TripDoctrineProvider::class,
            processor: TripDeleteProcessor::class,
        ),
    ],
)]
final readonly class Trip
{
    /**
     * @param array<string, string> $computationStatus Map of ComputationName->value to status string
     */
    public function __construct(
        public string $id,
        public array $computationStatus = [],
        public bool $isLocked = false,
    ) {
    }
}
