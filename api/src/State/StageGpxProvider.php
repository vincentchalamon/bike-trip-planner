<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<\App\ApiResource\Stage>
 */
final readonly class StageGpxProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $tripId = $uriVariables['tripId'] ?? null;
        $index = $uriVariables['index'] ?? null;

        if (!\is_string($tripId) || !is_numeric($index)) {
            throw new NotFoundHttpException('Trip or stage not found.');
        }

        $stageIndex = (int) $index;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages || !isset($stages[$stageIndex])) {
            throw new NotFoundHttpException(\sprintf('Stage %d not found for trip %s.', $stageIndex, $tripId));
        }

        $stage = $stages[$stageIndex];

        // Retrieve locale for translated label
        $locale = $this->tripStateManager->getLocale($tripId);
        if (null === $stage->label && null !== $locale) {
            $stage->label = \sprintf('Stage %d', $stage->dayNumber);
        }

        return $stage;
    }
}
