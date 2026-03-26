<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<TripRequest>
 */
final readonly class TripRequestProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    /**
     * @param array{id?: string} $uriVariables
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRequest
    {
        $id = $uriVariables['id'] ?? '';

        $request = $this->tripStateManager->getRequest($id);

        if (!$request instanceof TripRequest) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found or has expired.', $id));
        }

        return $request;
    }
}
