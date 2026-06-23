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
use App\State\AnalyzeTripProcessor;
use App\State\TripAiChatProcessor;
use App\State\TripBatchRecomputeProcessor;
use App\State\TripChatProcessor;
use App\State\TripAiGenerateProcessor;
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
            uriTemplate: '/trips/ai-generate{._format}',
            status: 202,
            openapi: new Operation(
                responses: [
                    422 => new Response(description: 'AI provider not configured'),
                    429 => new Response(description: 'Rate limit reached'),
                ],
                summary: "Generate a trip from a natural-language brief using the user's configured AI provider.",
            ),
            security: "is_granted('ROLE_USER')",
            input: TripAiGenerateRequest::class,
            mercure: true,
            processor: TripAiGenerateProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/ai-chat{._format}',
            status: 200,
            openapi: new Operation(
                responses: [
                    422 => new Response(description: 'AI provider not configured, or invalid/oversized conversation payload'),
                    429 => new Response(description: 'Rate limit reached'),
                    503 => new Response(description: 'AI assistant unavailable'),
                ],
                summary: "Refine a trip brief through a stateless multi-turn chat with the user's configured AI provider.",
            ),
            security: "is_granted('ROLE_USER')",
            input: AiChatRequest::class,
            output: AiChatResponse::class,
            processor: TripAiChatProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{id}/duplicate{._format}',
            status: 201,
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
        new Post(
            uriTemplate: '/trips/{id}/analyze{._format}',
            status: 202,
            openapi: new Operation(
                responses: [
                    404 => new Response(description: 'Trip not found'),
                    409 => new Response(description: 'An analysis is already in progress'),
                    422 => new Response(description: 'Trip has no stages to analyze'),
                ],
                summary: 'Trigger the full enrichment pipeline (POIs, weather, terrain, …) for a trip whose stages have been pre-computed.',
            ),
            security: "is_granted('TRIP_EDIT', object)",
            input: false,
            mercure: true,
            provider: TripRequestProvider::class,
            processor: AnalyzeTripProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{id}/ai-chat{._format}',
            status: 200,
            openapi: new Operation(
                responses: [
                    404 => new Response(description: 'Trip not found'),
                    429 => new Response(description: 'Rate limit reached'),
                    503 => new Response(description: 'AI assistant unavailable'),
                ],
                summary: 'Send a natural-language instruction to the LLaMA 3B dialogue assistant.',
            ),
            security: "is_granted('TRIP_EDIT', object)",
            input: TripChatRequest::class,
            output: TripChatResponse::class,
            provider: TripRequestProvider::class,
            processor: TripChatProcessor::class,
        ),
        new Post(
            uriTemplate: '/trips/{id}/recompute{._format}',
            status: 202,
            openapi: new Operation(
                responses: [
                    404 => new Response(description: 'Trip not found'),
                    422 => new Response(description: 'Trip has no stages to recompute'),
                ],
                summary: 'Apply a batch of pending modifications in a single recompute pass, dispatching only the minimal set of handlers needed.',
            ),
            security: "is_granted('TRIP_EDIT', object)",
            input: TripBatchRecomputeRequest::class,
            mercure: true,
            provider: TripRequestProvider::class,
            processor: TripBatchRecomputeProcessor::class,
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
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
                'fit' => ['application/vnd.ant.fit'],
            ],
            openapi: new Operation(summary: 'Download the full trip as a single GPX or FIT file containing all stages.'),
            security: "is_granted('TRIP_VIEW', request.attributes.get('id'))",
            provider: TripGpxProvider::class,
        ),
        new Delete(
            uriTemplate: '/trips/{id}',
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
