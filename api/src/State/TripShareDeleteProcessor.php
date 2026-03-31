<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Revokes a share link by deleting the TripShare entity.
 *
 * @implements ProcessorInterface<TripRequest, void>
 */
final readonly class TripShareDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param TripRequest                        $data         The trip entity (loaded by provider for security check)
     * @param Delete                             $operation
     * @param array{tripId?: string, shareId?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $shareId = $uriVariables['shareId'] ?? '';

        if (!Uuid::isValid($shareId)) {
            throw new NotFoundHttpException('Share link not found.');
        }

        $share = $this->entityManager->find(TripShare::class, Uuid::fromString($shareId));

        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Share link not found.');
        }

        $this->entityManager->remove($share);
        $this->entityManager->flush();
    }
}
