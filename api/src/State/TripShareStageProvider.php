<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Downloads a shared stage as GPX or FIT via short code (anonymous access).
 *
 * @implements ProviderInterface<Stage>
 */
final readonly class TripShareStageProvider implements ProviderInterface
{
    public function __construct(
        private SharedTripResolver $resolver,
        /** @var ProviderInterface<Stage> */
        #[Autowire(service: StageProvider::class)]
        private ProviderInterface $stageProvider,
    ) {
    }

    /**
     * @param array{shortCode?: string, stageId?: string} $uriVariables
     * @param array<string, mixed>                        $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Stage
    {
        $shortCode = $uriVariables['shortCode'] ?? '';

        $tripId = $this->resolver->resolve($shortCode);

        $stage = $this->stageProvider->provide($operation, ['tripId' => $tripId, 'stageId' => $uriVariables['stageId'] ?? ''], $context);
        \assert($stage instanceof Stage);

        return $stage;
    }
}
