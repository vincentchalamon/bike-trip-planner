<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\Model\AiChatMessage;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for `POST /trips/ai-chat` (ADR-045).
 *
 * Carries the full conversation so far — the endpoint is stateless, so the
 * client re-sends the growing message list on every turn. Server-side ceilings
 * (message count, per-message length) and strict role validation are enforced
 * both here (validation layer) and in {@see \App\State\TripAiChatProcessor}
 * before the LLM is ever called.
 */
final class AiChatRequest
{
    /**
     * Hard ceiling on the number of messages the client may post in a single
     * turn — roughly twice the client-side turn cap (ADR-045). Beyond this the
     * request is rejected with a 422.
     */
    public const int MAX_MESSAGES = 40;

    /**
     * @param list<AiChatMessage> $messages
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1, max: self::MAX_MESSAGES)]
        #[ApiProperty(description: 'The full conversation so far, sent by the client on every turn.')]
        public array $messages = [],
    ) {
    }
}
