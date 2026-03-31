<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\State\TripShareCreateProcessor;
use App\State\TripShareDeleteProcessor;
use App\State\TripShareListProvider;
use App\State\TripShareViewProvider;

#[ApiResource(
    shortName: 'TripShare',
    operations: [
        new Post(
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: [
                'tripId' => new Link(fromClass: TripShare::class),
            ],
            status: 201,
            openapi: new Operation(summary: 'Create a read-only share link for a trip.'),
            security: "is_granted('TRIP_EDIT', object)",
            input: TripShareRequest::class,
            output: TripShareResponse::class,
            provider: TripShareListProvider::class,
            processor: TripShareCreateProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/trips/{tripId}/shares',
            uriVariables: [
                'tripId' => new Link(fromClass: TripShare::class),
            ],
            openapi: new Operation(summary: 'List all share links for a trip.'),
            security: "is_granted('TRIP_EDIT', object)",
            output: TripShareResponse::class,
            provider: TripShareListProvider::class,
        ),
        new Delete(
            uriTemplate: '/trips/{tripId}/share/{shareId}',
            uriVariables: [
                'tripId' => new Link(fromClass: TripShare::class),
                'shareId' => new Link(fromClass: TripShare::class),
            ],
            openapi: new Operation(summary: 'Revoke a share link.'),
            security: "is_granted('TRIP_EDIT', object)",
            provider: TripShareListProvider::class,
            processor: TripShareDeleteProcessor::class,
        ),
        new Get(
            uriTemplate: '/share/{tripId}',
            uriVariables: [
                'tripId' => new Link(fromClass: TripShare::class),
            ],
            openapi: new Operation(
                summary: 'View a shared trip (read-only, anonymous access).',
                parameters: [
                    new Parameter(
                        name: 'token',
                        in: 'query',
                        description: 'Share token (64 hex characters)',
                        required: true,
                        schema: ['type' => 'string'],
                    ),
                ],
            ),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: TripDetail::class,
            provider: TripShareViewProvider::class,
        ),
    ],
)]
final readonly class TripShare
{
    public function __construct(
        public string $id = '',
    ) {
    }
}
