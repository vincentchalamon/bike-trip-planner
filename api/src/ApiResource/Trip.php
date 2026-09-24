<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Mcp\AnalyzeTripInput;
use App\ApiResource\Mcp\DeleteTripInput;
use App\State\AnalyzeTripProcessor;
use App\State\Mcp\McpAnalyzeTripProcessor;
use App\State\Mcp\McpConfirmationProcessor;
use App\State\Mcp\McpDeleteTripProcessor;
use App\State\NearbyPoiSearchProcessor;
use App\State\TripCreation;
use App\State\PreconditionProcessor;
use App\State\TripLockProcessor;
use App\State\TripBatchRecomputeProcessor;
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
            // A creation has no version to pin, so a retry after a dropped response makes a
            // second complete trip. The key is what makes asking twice safe (ADR-077).
            extraProperties: [TripCreation::REQUIRES_IDEMPOTENCY_KEY => true],
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
            extraProperties: [TripCreation::REQUIRES_IDEMPOTENCY_KEY => true],
        ),
        new Post(
            uriTemplate: '/trips/{id}/nearby-pois{._format}',
            status: 200,
            openapi: new Operation(
                responses: [
                    404 => new Response(description: 'Trip not found'),
                    422 => new Response(description: 'Unknown POI category or invalid request payload'),
                    429 => new Response(description: 'Rate limit reached'),
                ],
                summary: 'Search the nearest points of interest of one intent category around a rider mid-ride.',
            ),
            security: "is_granted('TRIP_VIEW', object)",
            input: NearbyPoiSearchRequest::class,
            output: NearbyPoiSearchResponse::class,
            provider: TripRequestProvider::class,
            processor: NearbyPoiSearchProcessor::class,
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
            // Re-runs the fifteen enrichments and replaces every stage's contents. On a trip
            // already under way that is not an edit, it is a surprise.
            extraProperties: [TripLockProcessor::EXTRA_PROPERTY => true],
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
            extraProperties: [PreconditionProcessor::EXTRA_PROPERTY => true],
        ),
        new Patch(
            uriTemplate: '/trips/{id}{._format}',
            status: 202,
            security: "is_granted('TRIP_EDIT', object)",
            input: TripRequest::class,
            mercure: true,
            provider: TripRequestProvider::class,
            processor: TripUpdateProcessor::class,
            extraProperties: [PreconditionProcessor::EXTRA_PROPERTY => true, TripLockProcessor::EXTRA_PROPERTY => true],
        ),
        // The canonical read, and the address every write response hands out as `@id`. It
        // declared gpx and fit only, so it answered 406 to `application/ld+json` — content
        // negotiation refusing the IRI the API itself distributes, ahead of the security stage
        // (ADR-074). One operation, three formats: `{._format}` already routes the two
        // downloads, and the URLs do not move.
        new Get(
            uriTemplate: '/trips/{id}{._format}',
            outputFormats: [
                'jsonld' => ['application/ld+json'],
                'gpx' => ['application/gpx+xml'],
                'fit' => ['application/vnd.ant.fit'],
            ],
            openapi: new Operation(summary: 'Read a trip, or download it as a single GPX or FIT file containing all stages.'),
            security: "is_granted('TRIP_VIEW', id)",
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
    mcp: [
        'list_trips' => new McpTool(
            name: 'list_trips',
            description: <<<'TEXT'
                List the trips of the authenticated user, most recently created first.
                Paginated: pass `page` to go further back. Optional `title`, `startDate` and
                `endDate` narrow the list. Returns a summary per trip; call `get_trip` with an
                id for its days, weather and alerts.
                TEXT,
            annotations: ['readOnlyHint' => true],
            uriTemplate: '/trips',
            // A tool inherits NOTHING from the operation it shadows — it is a separate
            // operation that merely shares a provider. Without these three, `Pagination`
            // reads the bundle's global defaults instead, `client_items_per_page` is false
            // there, and an `itemsPerPage` argument is silently ignored: the caller gets
            // twenty trips whatever it asks for, with nothing to indicate why. Only
            // `pagination_maximum_items_per_page` (30) comes from `api_platform.php`, because
            // it is declared under `defaults`.
            paginationEnabled: true,
            paginationItemsPerPage: 20,
            paginationClientItemsPerPage: true,
            // Ownership is the whole of it: the provider only ever selects the current user's
            // trips, so there is no object to authorize against and no id to leak.
            security: "is_granted('ROLE_USER')",
            provider: TripCollectionProvider::class,
            extraProperties: ['mcp_scope' => 'trips:read'],
        ),
        'analyze_trip' => new McpTool(
            name: 'analyze_trip',
            description: <<<'TEXT'
                Recompute everything known about each day of a trip: points of interest,
                accommodation options, weather, terrain, resupply, events and the alerts drawn
                from them. Returns immediately -- the work runs in the background, so call
                `get_trip` again shortly to see it arrive. Use this after changing the days of
                a trip, or when its information looks stale.
                TEXT,
            // No hint at all, and that is the honest answer. `idempotentHint` would be a lie:
            // the operation answers 409 when an analysis is already under way, so calling it
            // twice is not the same as calling it once. A false hint is worse than none —
            // it is the one thing a client acts on without checking.
            uriTemplate: '/trips/{id}/analyze',
            uriVariables: ['id' => new Link(fromClass: Trip::class)],
            security: "is_granted('TRIP_EDIT', id)",
            input: AnalyzeTripInput::class,
            validate: true,
            provider: TripRequestProvider::class,
            processor: McpAnalyzeTripProcessor::class,
            extraProperties: [
                'mcp_scope' => 'trips:write',
                // Replacing every stage's contents under someone who has already left is not
                // an edit, it is a surprise. Same flag as the HTTP operation, and it now
                // actually runs here (see config/services.php).
                TripLockProcessor::EXTRA_PROPERTY => true,
            ],
        ),
        'delete_trip' => new McpTool(
            name: 'delete_trip',
            description: <<<'TEXT'
                Delete a trip for good, with its days and any public link to it. Called without
                `confirmationToken`, this changes nothing: it answers with what would be lost
                and a token. Report that to the user and call again with the token only if they
                confirm.
                TEXT,
            annotations: ['destructiveHint' => true],
            uriTemplate: '/trips/{id}',
            uriVariables: ['id' => new Link(fromClass: Trip::class)],
            // `is_granted('TRIP_DELETE', id)`, where the HTTP operation beside it says
            // `object`. The object form only resolves once the provider has run, and the
            // provider reports a missing trip by throwing — so an unknown id would answer
            // "not found" while someone else's answers "denied", and the two become
            // distinguishable. On HTTP ADR-038's listener masks that; on this transport the
            // SDK catches the exception itself and `kernel.exception` never runs.
            security: "is_granted('TRIP_DELETE', id)",
            input: DeleteTripInput::class,
            validate: true,
            provider: TripDoctrineProvider::class,
            processor: McpDeleteTripProcessor::class,
            extraProperties: [
                'mcp_scope' => 'trips:write',
                McpConfirmationProcessor::EXTRA_PROPERTY => 'Delete this trip permanently, along with every day computed for it and any public link pointing at it. Nothing about it can be recovered afterwards.',
            ],
        ),
    ],
)]
final readonly class Trip
{
    // Every parameter is required, on purpose (ADR-074). None of the three properties is in a
    // serialization group, so a default is never *absent* from the body — it is emitted.
    // `computationStatus = []` and `isLocked = false` meant four processors answered a 202
    // claiming nothing was being computed and the trip was unlocked, which for a duplicate of
    // a past-dated trip was simply false. Required parameters make the compiler name every
    // site instead.
    //
    // Deliberately not a docblock: API Platform reads PHPDoc, and would publish this paragraph
    // as the schema description of the resource — or, on the constructor, of all three
    // properties at once, overwriting the one `computationStatus` has below.

    /**
     * @param array<string, string> $computationStatus Map of ComputationName->value to status string
     */
    public function __construct(
        public string $id,
        public array $computationStatus,
        public bool $isLocked,
    ) {
    }
}
