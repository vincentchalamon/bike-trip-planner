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
     * @param list<int>            $impactedStageNumbers Day numbers (1-indexed) whose recomputation has been dispatched.
     *                                                   Empty when the action is informational or requires full analysis.
     */
    public function __construct(
        #[ApiProperty(description: 'Trip identifier (UUID v7) the chat exchange belongs to.', required: true)]
        public string $tripId,
        #[ApiProperty(description: 'Action interpreted by the dialogue assistant (split_stage, info, ...).', required: true)]
        public string $action,
        #[ApiProperty(description: 'Parameters required to execute the action.', required: true, schema: ['type' => 'object', 'additionalProperties' => true])]
        public array $params,
        #[ApiProperty(description: 'Conversational reply to display to the rider.', required: true)]
        public string $response,
        #[ApiProperty(description: 'True when this action will trigger a backend recomputation (Messenger dispatch wired in #311).', required: true)]
        public bool $dispatched = false,
        #[ApiProperty(description: 'Day numbers (1-indexed) whose recomputation has been dispatched.')]
        public array $impactedStageNumbers = [],
        #[ApiProperty(description: 'True when the action requires a full trip re-analysis (Acte 2).')]
        public bool $requiresFullAnalysis = false,
    ) {
    }
}
