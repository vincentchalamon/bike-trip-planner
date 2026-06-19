<?php

declare(strict_types=1);

namespace App\Llm;

use App\Entity\User;

/**
 * Resolves the LLM client for a given user from their configured provider/token
 * (ADR-042). Implemented by {@see LlmClientFactory}; the interface seam lets sync
 * consumers (which resolve the current user via Security) depend on a mockable
 * contract.
 */
interface UserLlmResolverInterface
{
    public function forUser(User $user): ?ResolvedLlmClient;
}
