<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\StageResponse;
use App\Mapper\StageResponseMapper;
use App\Repository\TripRequestRepositoryInterface;

/**
 * Read one stage in full (geometry, resupply, accommodations, events, classified
 * alerts, weather) — the on-demand half of the split trip read model (ADR-057).
 * The roadbook loads only the summary; the detail is fetched when a stage opens.
 *
 * @implements ProviderInterface<StageResponse>
 */
final readonly class StageDetailProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private StageResponseMapper $mapper,
        private StageLocator $stageLocator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = \is_string($uriVariables['tripId'] ?? null) ? $uriVariables['tripId'] : '';
        $stageId = \is_string($uriVariables['stageId'] ?? null) ? $uriVariables['stageId'] : '';

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        return $this->mapper->map($stages[$this->stageLocator->indexOf($stages, $stageId)]);
    }
}
