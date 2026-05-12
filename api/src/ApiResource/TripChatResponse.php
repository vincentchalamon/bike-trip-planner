<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;

/**
 * Output DTO for the trip chat endpoint.
 *
 * Contains the structured action interpreted by the dialogue assistant alongside
 * the conversational response surfaced to the user in the chat panel.
 */
#[ApiResource(shortName: 'TripChat', operations: [])]
final readonly class TripChatResponse
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        #[ApiProperty(description: 'Trip identifier (UUID v7) the chat exchange belongs to.')]
        public string $tripId,
        #[ApiProperty(description: 'Action interpreted by the dialogue assistant (split_stage, info, ...).')]
        public string $action,
        #[ApiProperty(description: 'Parameters required to execute the action.')]
        public array $params,
        #[ApiProperty(description: 'Conversational reply to display to the rider.')]
        public string $response,
        #[ApiProperty(description: 'True when the action triggered a backend recomputation.')]
        public bool $dispatched = false,
    ) {
    }
}
