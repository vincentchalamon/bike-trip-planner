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
 * the Trip object. The already-fetched stages are passed through the
 * serialization context under the {@code trip_stages} key so that
 * {@see \App\Serializer\TripGpxNormalizer} can reuse them without an
 * additional Redis round-trip.
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
     * @param array{id?: string}   $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $id = $uriVariables['id'] ?? '';

        $stages = $this->tripStateManager->getStages($id);

        if (null === $stages) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found or has expired.', $id));
        }

        $context['trip_stages'] = $stages;

        return new Trip($id);
    }
}
