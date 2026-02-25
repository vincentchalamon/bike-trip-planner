<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stage;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<Stage>
 */
final readonly class StageProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    /**
     * @param array{tripId?: string, index?: int} $uriVariables
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Stage
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $index = \is_numeric($uriVariables['index'] ?? null) ? (int) $uriVariables['index'] : 0;

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

        return $stages[$index];
    }
}
