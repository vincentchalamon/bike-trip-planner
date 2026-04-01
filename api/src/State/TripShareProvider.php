<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides the active (non-deleted) TripShare for a given trip.
 *
 * Used by GET /trips/{tripId}/share and DELETE /trips/{tripId}/share.
 *
 * @implements ProviderInterface<TripShare>
 */
final readonly class TripShareProvider implements ProviderInterface
{
    public function __construct(
        private TripShareRepositoryInterface $tripShareRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripShare
    {
        $tripId = isset($uriVariables['tripId']) ? (string) $uriVariables['tripId'] : '';

        if ('' === $tripId) {
            throw new NotFoundHttpException('Active share not found.');
        }

        $share = $this->tripShareRepository->findActiveByTrip($tripId);

        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Active share not found.');
        }

        return $share;
    }
}
