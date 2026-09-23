<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRoute;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<TripRoute>
 */
final readonly class TripRouteProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRoute
    {
        $id = \is_string($uriVariables['id'] ?? null) ? $uriVariables['id'] : '';

        // The 404 used to come from getStages() answering null. It answers null for exactly
        // one reason — the trip row is missing — which is the same reason getVersion() does,
        // and that one costs a single column instead of the whole stage collection.
        if (null === $this->tripStateManager->getVersion($id)) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $id));
        }

        return new TripRoute(id: $id, stages: $this->tripStateManager->getRouteGeometry($id));
    }
}
