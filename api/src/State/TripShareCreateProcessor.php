<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TripShare;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates a 256-bit token before persisting a new TripShare.
 *
 * @implements ProcessorInterface<TripShare, TripShare>
 */
final readonly class TripShareCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripShare
    {
        \assert($data instanceof TripShare);

        $data->generateToken();

        $this->entityManager->persist($data);
        $this->entityManager->flush();

        return $data;
    }
}
