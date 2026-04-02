<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stage;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Downloads a shared stage as GPX or FIT via short code (anonymous access).
 *
 * @implements ProviderInterface<Stage>
 */
final readonly class TripShareStageProvider implements ProviderInterface
{
    public function __construct(
        private TripShareRepositoryInterface $tripShareRepository,
        /** @var ProviderInterface<Stage> */
        #[Autowire(service: StageProvider::class)]
        private ProviderInterface $stageProvider,
    ) {
    }

    /**
     * @param array{shortCode?: string, index?: int} $uriVariables
     * @param array<string, mixed>                   $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Stage
    {
        $shortCode = $uriVariables['shortCode'] ?? '';

        $share = '' !== $shortCode ? $this->tripShareRepository->findByShortCode($shortCode) : null;

        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $trip = $share->getTrip();
        if (!$trip instanceof TripRequest) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $tripId = (string) $trip->id;

        $stage = $this->stageProvider->provide($operation, ['tripId' => $tripId, 'index' => $uriVariables['index'] ?? 0], $context);
        \assert($stage instanceof Stage);

        return $stage;
    }
}
