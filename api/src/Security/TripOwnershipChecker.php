<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Voter\TripVoter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Verifies that the current user owns the given trip.
 *
 * Used by state providers and processors for Stage operations,
 * where the tripId comes from URI variables rather than the resource object.
 */
final readonly class TripOwnershipChecker
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @throws AccessDeniedHttpException if the current user does not own the trip
     */
    public function denyUnlessOwner(string $tripId): void
    {
        if (!$this->authorizationChecker->isGranted(TripVoter::TRIP_VIEW, $tripId)) {
            throw new AccessDeniedHttpException(\sprintf('Access denied to trip "%s".', $tripId));
        }
    }
}
