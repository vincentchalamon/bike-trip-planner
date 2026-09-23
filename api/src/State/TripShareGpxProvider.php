<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Trip;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Downloads a shared trip as GPX or FIT via short code (anonymous access).
 *
 * Format-agnostic: it resolves the share, delegates to {@see TripGpxProvider}
 * to load the trip + stages, and lets the format-specific normalizer
 * ({@see \App\Serializer\TripGpxNormalizer} / {@see \App\Serializer\TripFitNormalizer})
 * render the response. The "Gpx" name is historical.
 *
 * @implements ProviderInterface<Trip>
 */
final readonly class TripShareGpxProvider implements ProviderInterface
{
    public function __construct(
        private SharedTripResolver $resolver,
        /** @var ProviderInterface<Trip> */
        #[Autowire(service: TripGpxProvider::class)]
        private ProviderInterface $tripGpxProvider,
    ) {
    }

    /**
     * @param array{shortCode?: string} $uriVariables
     * @param array<string, mixed>      $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $shortCode = $uriVariables['shortCode'] ?? '';

        $tripId = $this->resolver->resolve($shortCode);

        $trip = $this->tripGpxProvider->provide($operation, ['id' => $tripId], $context);
        \assert($trip instanceof Trip);

        return $trip;
    }
}
