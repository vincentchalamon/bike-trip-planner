<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\OpenApi\Model\Operation;
use App\Enum\AlertCode;
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
            security: "is_granted('TRIP_VIEW', request.attributes.get('id'))",
            provider: TripDetailProvider::class,
        ),
    ],
    // SPIKE — throwaway. Same provider, same security expression as the HTTP Get
    // above: the point is to verify that a tool is an API Platform operation and
    // reuses the existing state pipeline unchanged.
    mcp: [
        'get_trip' => new McpTool(
            name: 'get_trip',
            description: 'Read one bikepacking trip: its pacing settings, dates and persisted stages (distance, elevation, labels, weather, terrain alerts, chosen accommodation).',
            uriTemplate: '/trips/{id}/detail',
            // SPIKE FINDING: without an explicit uriVariables declaration the tool's
            // `id` argument never reaches the provider — the call executes and dies with
            // `Trip "" not found.`
            uriVariables: ['id' => new Link(fromClass: TripDetail::class)],
            // SPIKE FINDING: the HTTP operation above uses
            //   security: "is_granted('TRIP_VIEW', request.attributes.get('id'))"
            // which CANNOT be reused here. At LISTING time (tools/list, and the CLI)
            // ExpressionAccessChecker passes request => $requestStack->getCurrentRequest(),
            // which is null outside HTTP, so `request.attributes` dies with
            //   Unable to get property "attributes" of non-object "request".
            //
            // `object` is the portable form: it is undefined at listing time, which
            // raises a SyntaxError that ExpressionAccessChecker catches on purpose —
            // the element stays visible and the expression is enforced on tools/call.
            // `object.id` rather than `object` because TripVoter::supports() accepts a
            // TripRequest entity or a string id, not this DTO.
            //
            // This form works for BOTH the HTTP operation and the tool, so the 19
            // request-based expressions in this codebase can be refactored coherently.
            security: "is_granted('TRIP_VIEW', object.id)",
            // SPIKE: per-operation format, global api_platform.mcp.format left unset.
            outputFormats: ['json' => ['application/json']],
            provider: TripDetailProvider::class,
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
            description: 'Per-block weather computation status derived from the ComputationTracker (WEATHER/WIND). Null when no computations are tracked (e.g. expired Redis TTL).',
            openapiContext: ['type' => ['string', 'null'], 'enum' => ['pending', 'running', 'done', 'failed', null]],
        )]
        public ?string $weatherStatus,
        #[ApiProperty(openapiContext: [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
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
                        // Stable rule-variant identifier; null on alerts persisted before issue #876.
                        // Enumerated so this endpoint gives consumers the same literal union as
                        // the Alert resource, instead of a bare string.
                        'code' => ['oneOf' => [['type' => 'string', 'enum' => AlertCode::VALUES], ['type' => 'null']]],
                        'type' => ['type' => 'string', 'enum' => ['critical', 'warning', 'nudge']],
                        'message' => ['type' => 'string'],
                        'lat' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                        'lon' => ['oneOf' => [['type' => 'number'], ['type' => 'null']]],
                        // Contextual action, restricted to the kinds the frontend wires (issue #863).
                        'action' => ['oneOf' => [['type' => 'object', 'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['navigate', 'dismiss']],
                            'label' => ['type' => 'string'],
                            'payload' => ['type' => 'object', 'additionalProperties' => true],
                        ]], ['type' => 'null']]],
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
