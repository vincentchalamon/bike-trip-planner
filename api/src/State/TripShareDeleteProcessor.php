<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TripShare;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Soft-deletes a TripShare by setting deletedAt instead of removing the row.
 *
 * @implements ProcessorInterface<TripShare, null>
 */
final readonly class TripShareDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        \assert($data instanceof TripShare);

        $data->softDelete();
        $this->entityManager->flush();

        return null;
    }
}
