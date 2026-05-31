<?php

declare(strict_types=1);

namespace App\State\Account;

use Symfony\Component\Uid\Uuid;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRequest;
use App\Entity\Stage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * GDPR right to portability: exports the current user's data as a downloadable
 * JSON archive (profile + trips + their preferences).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class AccountExportProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();

        \assert($user instanceof User);

        /** @var list<TripRequest> $trips */
        $trips = $this->entityManager->createQueryBuilder()
            ->select('t', 's')
            ->from(TripRequest::class, 't')
            ->leftJoin('t.stages', 's')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable();

        $data = [
            'exportedAt' => $now->format(\DateTimeInterface::ATOM),
            'profile' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getEmail(),
                'locale' => $user->getLocale(),
                'roles' => $user->getRoles(),
                'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'trips' => array_map($this->exportTrip(...), $trips),
        ];

        $response = new JsonResponse($data);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                \sprintf('bike-trip-planner-export-%s.json', $now->format('Y-m-d')),
            ),
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportTrip(TripRequest $trip): array
    {
        \assert($trip->id instanceof Uuid);

        return [
            'id' => $trip->id->toRfc4122(),
            'title' => $trip->title,
            'sourceUrl' => $trip->sourceUrl,
            'sourceType' => $trip->sourceType,
            'startDate' => $trip->startDate?->format('Y-m-d'),
            'endDate' => $trip->endDate?->format('Y-m-d'),
            'locale' => $trip->locale,
            'createdAt' => $trip->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $trip->updatedAt->format(\DateTimeInterface::ATOM),
            'preferences' => [
                'fatigueFactor' => $trip->fatigueFactor,
                'elevationPenalty' => $trip->elevationPenalty,
                'ebikeMode' => $trip->ebikeMode,
                'departureHour' => $trip->departureHour,
                'maxDistancePerDay' => $trip->maxDistancePerDay,
                'averageSpeed' => $trip->averageSpeed,
                'enabledAccommodationTypes' => $trip->enabledAccommodationTypes,
            ],
            'stages' => array_map($this->exportStage(...), $trip->stages->toArray()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportStage(Stage $stage): array
    {
        return [
            'dayNumber' => $stage->getDayNumber(),
            'label' => $stage->getLabel(),
            'distance' => $stage->getDistance(),
            'elevation' => $stage->getElevation(),
        ];
    }
}
