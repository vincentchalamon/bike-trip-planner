<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * A user's ready-to-use LLM client paired with the provider it was built for,
 * so callers can pick the right per-provider model (chat vs analysis) without a
 * second provider lookup (ADR-042).
 */
final readonly class ResolvedLlmClient
{
    public function __construct(
        public LlmClientInterface $client,
        public AiProvider $provider,
    ) {
    }
}
