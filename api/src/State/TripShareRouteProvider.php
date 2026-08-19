<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRequest;
use App\ApiResource\TripRoute;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a short code to the shared trip's route geometry (anonymous). The
 * public snapshot has no auth token, so it cannot call the authenticated
 * GET /route; this mirrors it via the short code (ADR-057).
 *
 * @implements ProviderInterface<TripRoute>
 */
final readonly class TripShareRouteProvider implements ProviderInterface
{
    public function __construct(
        private TripShareRepositoryInterface $tripShareRepository,
        /** @var ProviderInterface<TripRoute> */
        #[Autowire(service: TripRouteProvider::class)]
        private ProviderInterface $tripRouteProvider,
    ) {
    }

    /**
     * @param array{shortCode?: string} $uriVariables
     * @param array<string, mixed>      $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRoute
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

        $route = $this->tripRouteProvider->provide($operation, ['id' => (string) $trip->id], $context);
        \assert($route instanceof TripRoute);

        return $route;
    }
}
