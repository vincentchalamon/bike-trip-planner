<?php

declare(strict_types=1);

namespace App\State;

use App\Entity\TripShare;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripDetail;
use App\Repository\TripShareRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a read-only trip detail for anonymous share access.
 *
 * Validates the share token from query parameter before returning data.
 * Returns a uniform 404 for any invalid/expired/missing token to avoid
 * leaking information about trip existence.
 *
 * @implements ProviderInterface<TripDetail>
 */
final readonly class TripShareViewProvider implements ProviderInterface
{
    public function __construct(
        private TripShareRepository $tripShareRepository,
        private TripDetailProvider $tripDetailProvider,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array{tripId?: string} $uriVariables
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripDetail
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $token = $this->requestStack->getCurrentRequest()?->query->getString('token') ?? '';

        // Uniform error message: never reveal whether the trip exists
        if ('' === $token || '' === $tripId) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $share = $this->tripShareRepository->findValidShare($tripId, $token);

        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        // Delegate to TripDetailProvider's provide() by passing the tripId as 'id'
        return $this->tripDetailProvider->provide($operation, ['id' => $tripId], $context);
    }
}
