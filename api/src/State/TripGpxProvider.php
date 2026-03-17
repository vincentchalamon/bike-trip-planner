<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Trip;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a {@see Trip} resource for GPX export.
 *
 * Validates that the trip exists and has computed stages before returning
 * the Trip object. The {@see \App\Serializer\TripGpxNormalizer} will then
 * fetch the stages directly from the repository using the trip ID.
 *
 * @implements ProviderInterface<Trip>
 */
final readonly class TripGpxProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    /**
     * @param array{id?: string} $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $id = $uriVariables['id'] ?? '';

        $stages = $this->tripStateManager->getStages($id);

        if (null === $stages) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found or has expired.', $id));
        }

        return new Trip($id);
    }
}
