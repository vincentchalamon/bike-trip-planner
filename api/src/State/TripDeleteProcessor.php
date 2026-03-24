<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<TripRequest, void>
 */
final readonly class TripDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param TripRequest        $data
     * @param Delete             $operation
     * @param array{id?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        // $data is the TripRequest entity loaded by TripRequestProvider
        $this->entityManager->remove($data);
        $this->entityManager->flush();
    }
}
