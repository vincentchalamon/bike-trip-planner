<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Output DTO for `POST /trips/ai-chat` (ADR-045).
 *
 * The stateless trip-brief chat returns, per turn, the assistant reply, the
 * model's readiness verdict and the running structured summary of the brief.
 * It never routes: launching the computation reuses `POST /trips/ai-generate`.
 *
 * Not an `#[ApiResource]` of its own: API Platform 4 derives the response
 * schema from `output: AiChatResponse::class` on the `Post` operation of
 * {@see Trip}, so a standalone resource declaration (with no operations) only
 * added a redundant, unreachable schema.
 */
final readonly class AiChatResponse
{
    /**
     * @param array<string, scalar|null> $collected Running structured summary of the brief
     *                                              (e.g. start, end, durationDays, profile, …).
     */
    public function __construct(
        #[ApiProperty(description: 'Conversational reply to display to the rider.', required: true)]
        public string $reply,
        #[ApiProperty(description: 'True when the model judges the brief complete enough to launch generation.', required: true)]
        public bool $readyToGenerate,
        #[ApiProperty(description: 'Running structured summary of the brief understood so far.', required: true, schema: ['type' => 'object', 'additionalProperties' => true])]
        public array $collected,
    ) {
    }
}
