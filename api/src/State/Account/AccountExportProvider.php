<?php

declare(strict_types=1);

namespace App\State\Account;

use Symfony\Component\Uid\Uuid;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\OwnedTripFinderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Psr\Clock\ClockInterface;
use App\RateLimiter\RetryAfter;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * GDPR right to portability: exports the current user's data as a downloadable
 * JSON archive (profile + trips + their preferences).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class AccountExportProvider implements ProviderInterface
{
    public function __construct(
        private OwnedTripFinderInterface $trips,
        private Security $security,
        #[Target('account_export')]
        private RateLimiterFactoryInterface $accountExportLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();

        \assert($user instanceof User);

        // The export below walks every trip this user owns, with no upper bound: portability is
        // a once-in-a-while gesture and the limit says so.
        $limit = $this->accountExportLimiter->create($user->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(RetryAfter::seconds($limit, $this->clock));
        }

        $trips = $this->trips->findAllOwnedBy($user);
        $stagesByTripId = $this->trips->stageSummariesByTrip(array_map(static function (TripRequest $trip): Uuid {
            \assert($trip->id instanceof Uuid);

            return $trip->id;
        }, $trips));

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
