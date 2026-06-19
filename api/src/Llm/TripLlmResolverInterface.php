<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Resolves the LLM client to use for a trip's async analysis (ADR-042): finds the
 * trip owner and builds their configured provider client, or returns null when AI
 * is unavailable (kill-switch off, owner unknown, or owner has no token).
 */
interface TripLlmResolverInterface
{
    public function resolveForTrip(string $tripId): ?ResolvedLlmClient;
}
