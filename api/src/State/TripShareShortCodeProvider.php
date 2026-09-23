<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripDetail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves a short code to a shared trip detail (anonymous access).
 *
 * @implements ProviderInterface<TripDetail>
 */
final readonly class TripShareShortCodeProvider implements ProviderInterface
{
    public function __construct(
        private SharedTripResolver $resolver,
        /** @var ProviderInterface<TripDetail> */
        #[Autowire(service: TripDetailProvider::class)]
        private ProviderInterface $tripDetailProvider,
    ) {
    }

    /**
     * @param array{shortCode?: string} $uriVariables
     * @param array<string, mixed>      $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripDetail
    {
        $shortCode = $uriVariables['shortCode'] ?? '';

        $tripId = $this->resolver->resolve($shortCode);

        $detail = $this->tripDetailProvider->provide($operation, ['id' => $tripId], $context);
        \assert($detail instanceof TripDetail);

        return $detail;
    }
}
