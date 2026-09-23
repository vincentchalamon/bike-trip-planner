<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Enum\ComputationStatus;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\OpenApi\Model\Operation;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\WeatherAvailability;
use App\Enum\AlertGroup;
use App\State\TripDetailProvider;

/**
 * Read-only trip detail resource for loading a persisted trip on the frontend.
 *
 * Returns the trip configuration (pacing settings, dates, source URL) together
 * with all persisted stages, enabling the frontend to hydrate the Zustand store
 * from the database without triggering a recomputation.
 */
#[ApiResource(
    shortName: 'TripDetail',
    operations: [
        new Get(
            uriTemplate: '/trips/{id}/detail',
            openapi: new Operation(summary: 'Load trip configuration and persisted stages for frontend hydration.'),
            // Object-level authz (finding IDOR-DETAIL): without this, any authenticated
            // user could read another user's trip by UUID.
            security: "is_granted('TRIP_VIEW', id)",
            provider: TripDetailProvider::class,
        ),
    ],
    mcp: [
        'get_trip' => new McpTool(
            name: 'get_trip',
            description: 'Read one bikepacking trip: its pacing settings, dates and persisted stages (distance, elevation, labels, weather, terrain alerts, chosen accommodation).',
            uriTemplate: '/trips/{id}/detail',
            // Without an explicit declaration the tool's `id` argument never reaches the
            // provider and the call dies with `Trip "" not found.` — a McpTool receives its
            // uri variables, a McpResource does not (Mcp\Server\Handler, `if (!$isResource)`).
            uriVariables: ['id' => new Link(fromClass: TripDetail::class)],
            // Same expression as the HTTP operation above, and that is the point of ADR-063:
            // authorization is a property of the domain, so it survives a change of transport.
            // At listing time `id` is undefined, which raises a SyntaxError that
            // ExpressionAccessChecker swallows on purpose — the tool stays listed and the
            // expression is enforced on tools/call.
            security: "is_granted('TRIP_VIEW', id)",
            provider: TripDetailProvider::class,
            // The scope this tool consumes. Read here rather than repeated inside the
            // expression above, so there is one source of truth for what a token must carry.
            extraProperties: ['mcp_scope' => 'trips:read'],
        ),
    ],
)]
final readonly class TripDetail
{
    /**
     * @param list<array<string, mixed>> $stages Serialized stage DTOs
     */
    public function __construct(
        public string $id,
        public ?string $title,
        public ?string $sourceUrl,
        public ?\DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public float $fatigueFactor,
        public float $elevationPenalty,
        public float $maxDistancePerDay,
        public float $averageSpeed,
        public bool $ebikeMode,
        public int $departureHour,
        /** @var string[] */
        public array $enabledAccommodationTypes,
        public bool $isLocked,
        /** True when the route falls outside the provisioned coverage area: the trip is display-only (no Valhalla rerouting). */
        public bool $outOfZone,
        #[ApiProperty(
            description: 'Structural-readiness status (ADR-043): "draft" until pacing stages are persisted, then "ready".',
            openapiContext: ['type' => 'string', 'enum' => ['draft', 'ready']],
        )]
        public string $status,
        #[ApiProperty(
            description: 'Per-block weather computation status derived from the ComputationTracker (WEATHER/WIND). Null when no computations are tracked. Superseded by `categoryStatus`, which carries this value under the `weather` key.',
            openapiContext: ['type' => ['string', 'null'], 'enum' => [...ComputationStatus::VALUES, null]],
        )]
        public ?string $weatherStatus,
        /**
         * Where each family of enrichments stands, keyed by
         * {@see \App\Enum\ComputationName::category()}.
         *
         * Only the weather block was exposed before (ADR-072), so a client reloading
         * mid-analysis could not tell a terrain scan that had failed from one still running.
         * A category is absent when none of its computations is tracked, which a client reads
         * as "nothing to say" rather than as an outcome.
         *
         * @var array<string, string>
         */
        #[ApiProperty(
            description: 'Status of each enrichment family, keyed by category. A category is absent when none of its computations is tracked. `superseded` means the trip moved on before those computations settled and they were abandoned — nothing failed, and nothing is still running.',
            openapiContext: [
                'type' => 'object',
                // Not ComputationStatus::VALUES: `pending` never reaches a client here, because
                // deriveBlockStatus() collapses a block with anything still pending onto
                // `running`. Advertising a value the server cannot emit would be a wider
                // contract than the code honours.
                'additionalProperties' => ['type' => 'string', 'enum' => [
                    ComputationStatus::RUNNING->value,
                    ComputationStatus::DONE->value,
                    ComputationStatus::FAILED->value,
                    ComputationStatus::SUPERSEDED->value,
                ]],
            ],
        )]
        public array $categoryStatus,
        #[ApiProperty(openapiContext: [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    // Stable identity of the stage: what every stage operation and
                    // every SSE event addresses it by (ADR-066).
                    'stageId' => ['type' => 'string', 'format' => 'uuid'],
                    'dayNumber' => ['type' => 'integer'],
                    'distance' => ['type' => 'number', 'format' => 'float'],
                    'elevation' => ['type' => 'number', 'format' => 'float'],
                    'elevationLoss' => ['type' => 'number', 'format' => 'float'],
                    'startPoint' => ['type' => 'object', 'properties' => ['lat' => ['type' => 'number'], 'lon' => ['type' => 'number'], 'ele' => ['type' => 'number']]],
                    'endPoint' => ['type' => 'object', 'properties' => ['lat' => ['type' => 'number'], 'lon' => ['type' => 'number'], 'ele' => ['type' => 'number']]],
                    // Kept in the contract (optional) but omitted from the summary
                    // serialization (ADR-057): geometry loads on demand via GET /route.
                    // Declaring it keeps the frontend types stable while the payload
                    // stays light.
                    'geometry' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['lat' => ['type' => 'number'], 'lon' => ['type' => 'number'], 'ele' => ['type' => 'number']]]],
                    'label' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                    'startLabel' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                    'endLabel' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                    'isRestDay' => ['type' => 'boolean'],
                    'onCycleNetwork' => ['type' => 'number', 'format' => 'float', 'minimum' => 0, 'maximum' => 1],
                    // Why there is no forecast, when there is none — a past stage and one
                    // beyond the provider's horizon used to be the same bare null (ADR-072).
                    // Null when the forecast is there. `unavailable` is also withheld until
                    // the weather block has settled: before that the stage has no forecast
                    // *yet*, which `weatherStatus` says. The two calendar answers do not
                    // wait — they are true whether or not the computation has run.
                    'weatherAvailability' => ['oneOf' => [['type' => 'string', 'enum' => WeatherAvailability::VALUES], ['type' => 'null']]],
                    'weather' => ['oneOf' => [['type' => 'object', 'properties' => [
                        'icon' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'tempMin' => ['type' => 'number'],
                        'tempMax' => ['type' => 'number'],
                        'windSpeed' => ['type' => 'number'],
                        'windDirection' => ['type' => 'string'],
                        'precipitationProbability' => ['type' => 'integer'],
                        'humidity' => ['type' => 'integer'],
                        'comfortIndex' => ['type' => 'integer'],
                        'relativeWindDirection' => ['type' => 'string'],
                    ]], ['type' => 'null']]],
                    'alerts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        // The producer that owns the alert, and the unit in which alerts are
                        // replaced (ADR-068). Enumerated, so `core/schema.d.ts` types it as a
                        // literal union and a group the server does not know breaks the
                        // frontend build — which is why no drift test guards this list.
                        'group' => ['type' => 'string', 'enum' => AlertGroup::VALUES],
                        // Stable rule-variant identifier; null on alerts persisted before issue #876.
                        // Enumerated so this endpoint gives consumers the same literal union as
                        // the Alert resource, instead of a bare string.
                        'code' => ['oneOf' => [['type' => 'string', 'enum' => AlertCode::VALUES], ['type' => 'null']]],
                        'type' => ['type' => 'string', 'enum' => ['critical', 'warning', 'nudge']],
                        // Rendered here, in the reader's language, from the two fields below
                        // (ADR-069). The row itself holds no prose, so the same alert reads
                        // French to one account and English to the next.
                        'message' => ['type' => 'string'],
                        'messageKey' => ['type' => 'string'],
                        // Raw and unformatted: metres, not "2.4 km". Served next to the
                        // sentence so a client can phrase its own — an agent answering in a
                        // language this server was never told about.
                        'parameters' => ['type' => 'object', 'additionalProperties' => true],
                        'parameterFormats' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => AlertParameterFormat::VALUES]],
                        'lat' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                        'lon' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                        // Contextual action, restricted to the kinds the frontend wires (issue #863).
                        'action' => ['oneOf' => [['type' => 'object', 'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['navigate', 'dismiss']],
                            'labelKey' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'payload' => ['type' => 'object', 'additionalProperties' => true],
                        ]], ['type' => 'null']]],
                        // Producer-specific fields, carried verbatim rather than normalised
                        // away (ADR-068). Enumerated rather than left to
                        // `additionalProperties`, which would make the whole item type
                        // degenerate to `unknown` on the client and take the `group` union —
                        // and the guard that depends on it — down with it.
                        'source' => ['type' => 'string'],
                        'poiName' => ['type' => 'string'],
                        'poiType' => ['type' => 'string'],
                        'poiLat' => ['type' => 'number'],
                        'poiLon' => ['type' => 'number'],
                        'distanceFromRoute' => ['type' => 'number'],
                        'openingHours' => ['type' => 'string'],
                        'estimatedPrice' => ['type' => 'number'],
                        'description' => ['type' => 'string'],
                        'wikidataId' => ['type' => 'string'],
                        'imageUrl' => ['type' => 'string'],
                        'wikipediaUrl' => ['type' => 'string'],
                        'osmType' => ['oneOf' => [['type' => 'string', 'enum' => ['node', 'way', 'relation']], ['type' => 'null']]],
                        'osmId' => ['oneOf' => [['type' => 'integer'], ['type' => 'null']]],
                    ]]],
                    // Persisted since ADR-068 and served here, not only over SSE: the
                    // anonymous share page receives no SSE at all.
                    'events' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'lat' => ['type' => 'number'],
                        'lon' => ['type' => 'number'],
                        'startDate' => ['type' => 'string', 'format' => 'date-time'],
                        'endDate' => ['type' => 'string', 'format' => 'date-time'],
                        'url' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'description' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'priceMin' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                        'distanceToEndPoint' => ['type' => 'number'],
                        'source' => ['type' => 'string'],
                        'wikidataId' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'imageUrl' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'wikipediaUrl' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'openingHours' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                    ]]],
                    'supplyTimeline' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['water', 'food', 'both']],
                        'distanceFromStart' => ['type' => 'number'],
                        'lat' => ['type' => 'number'],
                        'lon' => ['type' => 'number'],
                        'water' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'name' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                            'lat' => ['type' => 'number'],
                            'lon' => ['type' => 'number'],
                            'distanceFromStart' => ['type' => 'number'],
                        ]]],
                        'food' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'name' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                            'category' => ['type' => 'string'],
                            'lat' => ['type' => 'number'],
                            'lon' => ['type' => 'number'],
                            'distanceFromStart' => ['type' => 'number'],
                        ]]],
                    ]]],
                    // Curated resupply suggestions (#1099), replacing the raw POI dump.
                    'resupply' => ['type' => 'object', 'properties' => [
                        'foodAtLunch' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'lat' => ['type' => 'number'],
                            'lon' => ['type' => 'number'],
                            'distanceFromStart' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                            'osmType' => ['oneOf' => [['type' => 'string', 'enum' => ['node', 'way', 'relation']], ['type' => 'null']]],
                            'osmId' => ['oneOf' => [['type' => 'integer'], ['type' => 'null']]],
                        ]]],
                        'waterMorning' => ['oneOf' => [['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'lat' => ['type' => 'number'],
                            'lon' => ['type' => 'number'],
                            'distanceFromStart' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                            'osmType' => ['oneOf' => [['type' => 'string', 'enum' => ['node', 'way', 'relation']], ['type' => 'null']]],
                            'osmId' => ['oneOf' => [['type' => 'integer'], ['type' => 'null']]],
                        ]], ['type' => 'null']]],
                        'waterAfternoon' => ['oneOf' => [['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'lat' => ['type' => 'number'],
                            'lon' => ['type' => 'number'],
                            'distanceFromStart' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                            'osmType' => ['oneOf' => [['type' => 'string', 'enum' => ['node', 'way', 'relation']], ['type' => 'null']]],
                            'osmId' => ['oneOf' => [['type' => 'integer'], ['type' => 'null']]],
                        ]], ['type' => 'null']]],
                        'foodAtArrival' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'lat' => ['type' => 'number'],
                            'lon' => ['type' => 'number'],
                            'distanceFromStart' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                            'osmType' => ['oneOf' => [['type' => 'string', 'enum' => ['node', 'way', 'relation']], ['type' => 'null']]],
                            'osmId' => ['oneOf' => [['type' => 'integer'], ['type' => 'null']]],
                        ]]],
                    ]],
                    'accommodations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'lat' => ['type' => 'number'],
                        'lon' => ['type' => 'number'],
                        'estimatedPriceMin' => ['type' => 'number'],
                        'estimatedPriceMax' => ['type' => 'number'],
                        'isExactPrice' => ['type' => 'boolean'],
                        'url' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'possibleClosed' => ['type' => 'boolean'],
                        'distanceToEndPoint' => ['type' => 'number'],
                    ]]],
                    'selectedAccommodation' => ['oneOf' => [['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'lat' => ['type' => 'number'],
                        'lon' => ['type' => 'number'],
                        'estimatedPriceMin' => ['type' => 'number'],
                        'estimatedPriceMax' => ['type' => 'number'],
                        'isExactPrice' => ['type' => 'boolean'],
                        'url' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'possibleClosed' => ['type' => 'boolean'],
                        'distanceToEndPoint' => ['type' => 'number'],
                    ]], ['type' => 'null']]],
                ],
            ],
        ])]
        /** @var list<array<string, mixed>> */
        public array $stages,
    ) {
    }
}
