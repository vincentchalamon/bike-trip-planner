<?php

declare(strict_types=1);

namespace App\State\Account;

use Symfony\Component\Uid\Uuid;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

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
        #[Autowire(service: 'limiter.account_export')]
        private RateLimiterFactory $exportLimiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();

        \assert($user instanceof User);

        // The query below walks every trip this user owns, with no upper bound: portability is
        // a once-in-a-while gesture and the limit says so.
        if (!$this->exportLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        /** @var list<TripRequest> $trips */
        $trips = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(TripRequest::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $stagesByTripId = $this->exportStages($trips);

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
            'trips' => array_map(
                fn (TripRequest $trip): array => $this->exportTrip($trip, $stagesByTripId),
                $trips,
            ),
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
     * The four stage columns the export ships, read without the aggregate around them.
     *
     * The trips used to be fetch-joined with their stages, which hydrated eight JSONB columns
     * per stage — geometry, weather, alerts, accommodations — none of which leaves the
     * server: only the four below do. A scalar read carries its own ORDER BY, because the
     * `#[ORM\OrderBy]` on the association only applies when the collection is the thing being
     * loaded; sorting globally by position keeps each trip's own rows ascending.
     *
     * @param list<TripRequest> $trips
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function exportStages(array $trips): array
    {
        if ([] === $trips) {
            return [];
        }

        /** @var list<array{tripId: string, dayNumber: int, label: string|null, distance: float, elevation: float}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(s.trip) AS tripId, s.dayNumber, s.label, s.distance, s.elevation
             FROM App\Entity\Stage s
             WHERE s.trip IN (:tripIds)
             ORDER BY s.position ASC',
        )
            ->setParameter('tripIds', array_column($trips, 'id'))
            ->getResult();

        $byTripId = [];
        foreach ($rows as $row) {
            $byTripId[Uuid::fromString($row['tripId'])->toRfc4122()][] = [
                'dayNumber' => $row['dayNumber'],
                'label' => $row['label'],
                'distance' => $row['distance'],
                'elevation' => $row['elevation'],
            ];
        }

        return $byTripId;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $stagesByTripId
     *
     * @return array<string, mixed>
     */
    private function exportTrip(TripRequest $trip, array $stagesByTripId): array
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
            // Never $trip->stages here: without the fetch-join that is a lazy load per trip.
            'stages' => $stagesByTripId[$trip->id->toRfc4122()] ?? [],
        ];
    }
}
