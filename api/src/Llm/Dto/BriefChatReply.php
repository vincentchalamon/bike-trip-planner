<?php

declare(strict_types=1);

namespace App\Llm\Dto;

/**
 * Parsed reply of the stateless trip-brief chat (ADR-045).
 *
 * Produced by {@see \App\Llm\BriefChatInterpreter} from the JSON envelope emitted
 * by the `brief-chat` system prompt. Carries the conversational reply, the
 * model's readiness verdict and the running structured summary of what it has
 * understood so far.
 */
final readonly class BriefChatReply
{
    /**
     * @param array<string, scalar|null> $collected
     */
    public function __construct(
        public string $reply,
        public bool $readyToGenerate,
        public array $collected,
    ) {
    }
}
