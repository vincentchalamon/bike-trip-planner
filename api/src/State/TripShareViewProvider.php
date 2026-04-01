<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripDetail;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a read-only trip detail for anonymous share access.
 *
 * Validates the share token from query parameter before returning data.
 * Returns a uniform 404 for any invalid/revoked/missing token to avoid
 * leaking information about trip existence.
 *
 * @implements ProviderInterface<TripDetail>
 */
final readonly class TripShareViewProvider implements ProviderInterface
{
    public function __construct(
        private TripShareRepositoryInterface $tripShareRepository,
        /** @var ProviderInterface<TripDetail> */
        #[Autowire(service: TripDetailProvider::class)]
        private ProviderInterface $tripDetailProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripDetail
    {
        $tripId = isset($uriVariables['tripId']) ? (string) $uriVariables['tripId'] : '';
        $request = $context['request'] ?? null;
        $token = $request instanceof Request ? $request->query->getString('token') : '';

        // Uniform error message: never reveal whether the trip exists
        if ('' === $token || '' === $tripId) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $share = $this->tripShareRepository->findValidShare($tripId, $token);

        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        // Delegate to TripDetailProvider's provide() by passing the tripId as 'id'
        $detail = $this->tripDetailProvider->provide($operation, ['id' => $tripId], $context);
        \assert($detail instanceof TripDetail);

        return $detail;
    }
}
