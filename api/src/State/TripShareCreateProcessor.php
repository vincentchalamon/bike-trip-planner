<?php

declare(strict_types=1);

namespace App\State;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Generates a 256-bit token before delegating persistence to API Platform's PersistProcessor.
 *
 * @implements ProcessorInterface<TripShare, TripShare>
 */
final readonly class TripShareCreateProcessor implements ProcessorInterface
{
    public function __construct(
        /** @var ProcessorInterface<TripShare, TripShare> */
        #[Autowire(service: PersistProcessor::class)]
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripShare
    {
        \assert($data instanceof TripShare);

        // API Platform's Link(toProperty: 'trip') does not inject the trip into the
        // entity on POST operations because $trip is not writable via the denormalizer.
        // Fetch it explicitly from the URI variable.
        $tripId = $uriVariables['tripId'] ?? null;
        if (is_string($tripId)) {
            $trip = $this->entityManager->find(TripRequest::class, Uuid::fromString($tripId));
            if (!$trip instanceof TripRequest) {
                throw new NotFoundHttpException(sprintf('Trip "%s" not found.', $tripId));
            }

            $data->setTrip($trip);
        }

        $data->generateToken();

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        \assert($result instanceof TripShare);

        return $result;
    }
}
