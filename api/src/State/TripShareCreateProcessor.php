<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Creates a TripShare: resolves the trip, checks for active share (409),
 * generates a 256-bit token, and persists.
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
        private TripShareRepositoryInterface $tripShareRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripShare
    {
        \assert($data instanceof TripShare);

        $trip = $this->resolveTrip($uriVariables);

        if ($trip instanceof TripRequest) {
            $data->setTrip($trip);

            // Only one active share per trip
            if ($this->tripShareRepository->findActiveByTrip((string) $trip->id) instanceof TripShare) {
                throw new ConflictHttpException('An active share link already exists for this trip.');
            }
        }

        $data->generateToken();

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        \assert($result instanceof TripShare);

        return $result;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function resolveTrip(array $uriVariables): ?TripRequest
    {
        $tripId = $uriVariables['tripId'] ?? null;

        if ($tripId instanceof TripRequest) {
            return $tripId;
        }

        if ($tripId instanceof Uuid || is_string($tripId)) {
            try {
                $uuid = $tripId instanceof Uuid ? $tripId : Uuid::fromString($tripId);
            } catch (\InvalidArgumentException) {
                throw new NotFoundHttpException(sprintf('Trip "%s" not found.', $tripId));
            }

            $trip = $this->entityManager->find(TripRequest::class, $uuid);
            if (!$trip instanceof TripRequest) {
                throw new NotFoundHttpException(sprintf('Trip "%s" not found.', $uuid));
            }

            return $trip;
        }

        return null;
    }
}
