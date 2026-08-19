<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\TripRouteProvider;

/**
 * Route geometry for the whole trip, split off the trip read model (ADR-057):
 * the map tab fetches it on demand instead of every trip load paying the parse
 * cost of the decimated points on a months-long trip.
 */
#[ApiResource(
    shortName: 'TripRoute',
    operations: [
        new Get(
            uriTemplate: '/trips/{id}/route',
            openapi: new Operation(summary: 'All-stages decimated geometry for the map (loaded on demand).'),
            security: "is_granted('TRIP_VIEW', request.attributes.get('id'))",
            provider: TripRouteProvider::class,
        ),
    ],
)]
final readonly class TripRoute
{
    /**
     * @param list<array{dayNumber: int, geometry: list<array{lat: float, lon: float, ele: float}>}> $stages
     */
    public function __construct(
        public string $id,
        #[ApiProperty(openapiContext: [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'dayNumber' => ['type' => 'integer'],
                    'geometry' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'lat' => ['type' => 'number'],
                        'lon' => ['type' => 'number'],
                        'ele' => ['type' => 'number'],
                    ]]],
                ],
            ],
        ])]
        public array $stages,
    ) {
    }
}
