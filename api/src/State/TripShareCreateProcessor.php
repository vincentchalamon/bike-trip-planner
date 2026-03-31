<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\ApiResource\TripShareRequest;
use App\ApiResource\TripShareResponse;
use App\Entity\TripShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Creates a new share token for a trip.
 *
 * @implements ProcessorInterface<TripShareRequest, TripShareResponse>
 */
final readonly class TripShareCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param TripShareRequest                   $data         The deserialized input DTO
     * @param Post                               $operation
     * @param array{tripId?: string}             $uriVariables
     * @param array{previous_data?: TripRequest} $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripShareResponse
    {
        \assert(isset($context['previous_data']) && $context['previous_data'] instanceof TripRequest);
        $trip = $context['previous_data'];

        $token = bin2hex(random_bytes(32)); // 256 bits = 64 hex chars

        $expiresAt = null;
        if ($data instanceof TripShareRequest && null !== $data->expiresInHours) {
            $expiresAt = new \DateTimeImmutable(\sprintf('+%d hours', $data->expiresInHours));
        }

        $share = new TripShare(
            trip: $trip,
            token: $token,
            expiresAt: $expiresAt,
        );

        $this->entityManager->persist($share);
        $this->entityManager->flush();

        $tripId = $uriVariables['tripId'] ?? '';
        $shareUrl = $this->buildShareUrl($tripId, $token);

        return new TripShareResponse(
            id: $share->getId()->toRfc4122(),
            shareUrl: $shareUrl,
            token: $token,
            expiresAt: $expiresAt,
            createdAt: $share->getCreatedAt(),
        );
    }

    private function buildShareUrl(string $tripId, string $token): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $baseUrl = $request?->getSchemeAndHttpHost() ?? 'https://localhost';

        // The share URL points to the frontend, not the API
        // Use the PWA origin from the request's Referer/Origin or fallback to env
        $pwaUrl = $_ENV['PWA_URL'] ?? $baseUrl;

        return \sprintf('%s/share/%s?token=%s', rtrim(\is_string($pwaUrl) ? $pwaUrl : $baseUrl, '/'), $tripId, $token);
    }
}
