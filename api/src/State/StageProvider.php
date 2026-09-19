<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stage;
use App\Repository\TripRequestRepositoryInterface;

/**
 * @implements ProviderInterface<Stage>
 */
final readonly class StageProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private StageLocator $stageLocator,
    ) {
    }

    /**
     * @param array{tripId?: string, stageId?: string} $uriVariables
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Stage
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $stageId = $uriVariables['stageId'] ?? '';

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        return $stages[$this->stageLocator->indexOf($stages, $stageId)];
    }
}
