<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRequest;
use App\ApiResource\TripShareResponse;
use App\Entity\TripShare;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripShareRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides the TripRequest subject for security voter checks on share operations,
 * and returns the list of shares for GET collection.
 *
 * For POST: returns the TripRequest (used as security subject, processor handles creation).
 * For GET collection: returns a list of TripShareResponse DTOs.
 * For DELETE: returns the TripRequest (processor handles deletion).
 *
 * @implements ProviderInterface<TripRequest>
 */
final readonly class TripShareListProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private TripShareRepository $tripShareRepository,
        #[Autowire(env: 'PWA_URL')]
        private string $pwaUrl,
    ) {
    }

    /**
     * @param array{tripId?: string, shareId?: string} $uriVariables
     *
     * @return TripRequest|list<TripShareResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRequest|array
    {
        $tripId = $uriVariables['tripId'] ?? '';

        $request = $this->tripStateManager->getRequest($tripId);

        if (!$request instanceof TripRequest) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $tripId));
        }

        // For GET collection, return the list of share DTOs
        if ($operation instanceof GetCollection) {
            return $this->buildShareList($tripId);
        }

        // For POST and DELETE, return the TripRequest for security voter
        return $request;
    }

    /**
     * @return list<TripShareResponse>
     */
    private function buildShareList(string $tripId): array
    {
        $shares = $this->tripShareRepository->findByTrip($tripId);

        return array_map(
            fn (TripShare $share): TripShareResponse => new TripShareResponse(
                id: $share->getId()->toRfc4122(),
                shareUrl: \sprintf('%s/share/%s?token=%s', rtrim($this->pwaUrl, '/'), $tripId, $share->getToken()),
                token: $share->getToken(),
                expiresAt: $share->getExpiresAt(),
                createdAt: $share->getCreatedAt(),
            ),
            $shares,
        );
    }
}
