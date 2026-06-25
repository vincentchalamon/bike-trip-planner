<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TripShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft-deletes a TripShare by setting deletedAt instead of removing the row.
 *
 * @implements ProcessorInterface<TripShare, Response>
 */
final readonly class TripShareDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        \assert($data instanceof TripShare);

        $data->softDelete();
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
