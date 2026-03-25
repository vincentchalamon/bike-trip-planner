<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
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
use App\State\TripDuplicateProcessor;
use App\State\TripGpxProvider;
use App\State\TripRequestProvider;
use App\State\TripUpdateProcessor;

#[ApiResource(
    shortName: 'Trip',
    operations: [
        new GetCollection(
            uriTemplate: '/trips',
            security: "is_granted('ROLE_USER')",
            openapi: new Operation(
                summary: 'List all trips, paginated and filterable.',
                parameters: [
                    new Parameter(
                        name: 'title',
                        in: 'query',
                        description: 'Filter by title (case-insensitive partial match)',
                        required: false,
                        schema: ['type' => 'string'],
                    ),
                    new Parameter(
                        name: 'startDate',
                        in: 'query',
                        description: 'Filter trips starting on or after this date (YYYY-MM-DD)',
                        required: false,
                        schema: ['type' => 'string', 'format' => 'date'],
                    ),
                    new Parameter(
                        name: 'endDate',
                        in: 'query',
                        description: 'Filter trips ending on or before this date (YYYY-MM-DD)',
                        required: false,
                        schema: ['type' => 'string', 'format' => 'date'],
                    ),
                ],
            ),
            paginationEnabled: true,
            paginationItemsPerPage: 20,
            paginationClientItemsPerPage: true,
            security: "is_granted('ROLE_USER')",
            output: TripListItem::class,
            provider: TripCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/trips{._format}',
            status: 202,
            security: "is_granted('ROLE_USER')",
            validationContext: ['groups' => ['trip_request:create']],
            input: TripRequest::class,
            mercure: true,
            processor: TripCreateProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{id}/duplicate{._format}',
            status: 201,
            security: "is_granted('TRIP_VIEW', object)",
            openapi: new Operation(
                responses: [
                    404 => new Response(description: 'Trip not found'),
                ],
                summary: 'Duplicate an existing trip, deep-cloning all its stages and settings.',
            ),
            security: "is_granted('TRIP_VIEW', object)",
            input: false,
            provider: TripRequestProvider::class,
            processor: TripDuplicateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/trips/{id}{._format}',
            status: 202,
            security: "is_granted('TRIP_EDIT', object)",
            input: TripRequest::class,
            mercure: true,
            provider: TripRequestProvider::class,
            processor: TripUpdateProcessor::class,
        ),
        new Get(
            uriTemplate: '/trips/{id}{._format}',
            security: "is_granted('TRIP_VIEW', object)",
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
            ],
            openapi: new Operation(summary: 'Download the full trip as a single GPX file containing all stages.'),
            security: "is_granted('TRIP_VIEW', object)",
            provider: TripGpxProvider::class,
        ),
        new Delete(
            uriTemplate: '/trips/{id}',
            security: "is_granted('TRIP_DELETE', object)",
            openapi: new Operation(summary: 'Delete a trip and all its stages.'),
            security: "is_granted('TRIP_DELETE', object)",
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
