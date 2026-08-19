<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\StageResponse;
use App\Mapper\StageResponseMapper;
use App\Repository\DoctrineTripRequestRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read one stage in full (geometry, resupply, accommodations, events, classified
 * alerts, weather) — the on-demand half of the split trip read model (ADR-057).
 * The roadbook loads only the summary; the detail is fetched when a stage opens.
 *
 * `index` is the 0-based stage position, matching {@see StageProvider}.
 *
 * @implements ProviderInterface<StageResponse>
 */
final readonly class StageDetailProvider implements ProviderInterface
{
    public function __construct(
        private DoctrineTripRequestRepository $tripStateManager,
        private StageResponseMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = \is_string($uriVariables['tripId'] ?? null) ? $uriVariables['tripId'] : '';
        $index = \is_numeric($uriVariables['index'] ?? null) ? (int) $uriVariables['index'] : 0;

        $stages = $this->tripStateManager->getStages($tripId) ?? [];
        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

        return $this->mapper->map($stages[$index]);
    }
}
