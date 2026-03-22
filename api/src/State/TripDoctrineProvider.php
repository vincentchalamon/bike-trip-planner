<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRequest;
use App\Repository\DoctrineTripRequestRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a TripRequest loaded from PostgreSQL by ID.
 *
 * Used for operations that need a Doctrine-managed entity (e.g. DELETE).
 *
 * @implements ProviderInterface<TripRequest>
 */
final readonly class TripDoctrineProvider implements ProviderInterface
{
    public function __construct(
        private DoctrineTripRequestRepository $repository,
    ) {
    }

    /**
     * @param array{id?: string} $uriVariables
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRequest
    {
        $id = $uriVariables['id'] ?? '';

        $trip = $this->repository->getRequest($id);

        if (!$trip instanceof TripRequest) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $id));
        }

        return $trip;
    }
}
