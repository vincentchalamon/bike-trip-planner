<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripDetail;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a short code to a shared trip detail (anonymous access).
 *
 * @implements ProviderInterface<TripDetail>
 */
final readonly class TripShareShortCodeProvider implements ProviderInterface
{
    public function __construct(
        private TripShareRepositoryInterface $tripShareRepository,
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

        $share = '' !== $shortCode ? $this->tripShareRepository->findByShortCode($shortCode) : null;

        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $trip = $share->getTrip();
        if (!$trip instanceof TripRequest) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $tripId = (string) $trip->id;

        $detail = $this->tripDetailProvider->provide($operation, ['id' => $tripId], $context);
        \assert($detail instanceof TripDetail);

        return $detail;
    }
}
