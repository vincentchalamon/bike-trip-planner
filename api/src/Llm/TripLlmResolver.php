<?php

declare(strict_types=1);

namespace App\Llm;

use App\Entity\User;

/**
 * Default {@see TripLlmResolverInterface}: resolves the trip owner via
 * {@see TripOwnerResolver} then builds their provider client via
 * {@see LlmClientFactory}.
 */
final readonly class TripLlmResolver implements TripLlmResolverInterface
{
    public function __construct(
        private TripOwnerResolver $ownerResolver,
        private LlmClientFactory $clientFactory,
    ) {
    }

    public function resolveForTrip(string $tripId): ?ResolvedLlmClient
    {
        $owner = $this->ownerResolver->resolve($tripId);

        return $owner instanceof User ? $this->clientFactory->forUser($owner) : null;
    }
}
