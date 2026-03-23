<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
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
                    'geometry' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['lat' => ['type' => 'number'], 'lon' => ['type' => 'number'], 'ele' => ['type' => 'number']]]],
                    'label' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                    'isRestDay' => ['type' => 'boolean'],
                ],
            ],
        ])]
        /** @var list<array<string, mixed>> */
        public array $stages,
    ) {
    }
}
