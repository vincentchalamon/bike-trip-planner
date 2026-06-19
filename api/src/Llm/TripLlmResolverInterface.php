<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Resolves the LLM client to use for a trip's async analysis (ADR-042): finds the
 * trip owner and builds their configured provider client, or returns null when AI
 * is unavailable (owner unknown, or the owner has no provider/token configured).
 */
interface TripLlmResolverInterface
{
    public function resolveForTrip(string $tripId): ?ResolvedLlmClient;
}
