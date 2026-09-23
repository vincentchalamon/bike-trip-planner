<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a short code to the shared trip's route geometry (anonymous). The
 * public snapshot has no auth token, so it cannot call the authenticated
 * GET /route; this mirrors it via the short code (ADR-057).
 *
 * @implements ProviderInterface<TripRoute>
 */
final readonly class TripShareRouteProvider implements ProviderInterface
{
    public function __construct(
        private SharedTripResolver $resolver,
        // The class, not the interface: only this signature admits the bare 304 Response
        // this provider has to pass through. Behind ProviderInterface the return narrows to
        // TripRoute and the conditional answer dies with a TypeError, on the anonymous route
        // alone — which is exactly the path no unit test covers.
        private TripRouteProvider $tripRouteProvider,
    ) {
    }

    /**
     * @param array{shortCode?: string} $uriVariables
     * @param array<string, mixed>      $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRoute|Response
    {
        $shortCode = $uriVariables['shortCode'] ?? '';

        // The resolver enforces revocation (deletedAt IS NULL) and the anonymous rate limit,
        // and it must stay ahead of the conditional answer the delegate may give: confirming a
        // cached copy of a revoked share is still current would un-revoke it.
        $tripId = $this->resolver->resolve($shortCode);

        return $this->tripRouteProvider->provide($operation, ['id' => $tripId], $context);
    }
}
