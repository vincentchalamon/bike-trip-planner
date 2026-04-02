<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Trip;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Downloads a shared trip as GPX via short code (anonymous access).
 *
 * @implements ProviderInterface<Trip>
 */
final readonly class TripShareGpxProvider implements ProviderInterface
{
    public function __construct(
        private TripShareRepositoryInterface $tripShareRepository,
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

        $share = '' !== $shortCode ? $this->tripShareRepository->findByShortCode($shortCode) : null;

        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $trip = $share->getTrip();
        if (!$trip instanceof TripRequest) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $tripId = (string) $trip->id;

        $trip = $this->tripGpxProvider->provide($operation, ['id' => $tripId], $context);
        \assert($trip instanceof Trip);

        return $trip;
    }
}
