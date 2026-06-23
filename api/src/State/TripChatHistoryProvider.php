<?php

declare(strict_types=1);

namespace App\State;

use Symfony\Component\HttpFoundation\Request;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Model\PoiSuggestionDto;
use App\ApiResource\TripChatMessageResource;
use App\Entity\TripChatMessage;
use App\Entity\User;
use App\Repository\TripChatMessageRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the persisted chat history for a trip with cursor-based pagination.
 *
 * Companion provider for {@see TripChatMessageResource} backing
 * `GET /trips/{id}/ai-chat-history`. The Redis-backed Mercure history is volatile
 * and capped to the most recent N turns; this endpoint reads the durable
 * PostgreSQL store so the PWA can rehydrate the chat drawer days later.
 *
 * Security: API Platform's `is_granted('TRIP_VIEW', ...)` gate already enforces
 * trip ownership upstream. This provider additionally scopes the query by the
 * authenticated user's id so a future change that broadens the voter (e.g.
 * shared trips) cannot leak another rider's private chat turns.
 *
 * @implements ProviderInterface<TripChatMessageResource>
 */
final readonly class TripChatHistoryProvider implements ProviderInterface
{
    public function __construct(
        private TripChatMessageRepository $repository,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array{id?: string} $uriVariables
     *
     * @return list<TripChatMessageResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $tripId = $uriVariables['id'] ?? '';
        if ('' === $tripId || !Uuid::isValid($tripId)) {
            return [];
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        $request = $this->requestStack->getCurrentRequest();
        $limit = TripChatMessageRepository::DEFAULT_LIMIT;
        $before = null;

        if ($request instanceof Request) {
            $rawLimit = $request->query->get('limit');
            if (\is_string($rawLimit) && '' !== $rawLimit) {
                if (!ctype_digit($rawLimit)) {
                    throw new HttpException(400, \sprintf('Invalid "limit": "%s" is not a positive integer.', $rawLimit));
                }

                $candidate = (int) $rawLimit;
                if ($candidate < 1) {
                    throw new HttpException(400, 'Invalid "limit": must be >= 1.');
                }

                $limit = min($candidate, TripChatMessageRepository::MAX_LIMIT);
            }

            $rawBefore = $request->query->get('before');
            if (\is_string($rawBefore) && '' !== $rawBefore) {
                // Constrain to RFC 3339 so relative expressions like "next week" or
                // "+1 year" — accepted by the permissive DateTimeImmutable parser —
                // do not silently yield unexpected cursor windows.
                $before = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $rawBefore)
                    ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $rawBefore);

                if (!$before instanceof \DateTimeImmutable) {
                    throw new HttpException(400, \sprintf('Invalid "before" cursor: "%s" is not a valid RFC 3339 datetime.', $rawBefore));
                }

                // `created_at` is persisted as UTC in a `TIMESTAMP(6) WITHOUT TIME ZONE`
                // column; Doctrine binds DateTimeImmutable parameters in their own
                // timezone, so a cursor with a non-UTC offset would produce a wrong
                // SQL boundary. Normalise to UTC defensively.
                $before = $before->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        $messages = $this->repository->findHistory(
            tripId: $tripId,
            userId: $user->getId()->toRfc4122(),
            limit: $limit,
            before: $before,
        );

        return array_map(
            fn (TripChatMessage $entity): TripChatMessageResource => $this->toResource($entity, $tripId),
            $messages,
        );
    }

    private function toResource(TripChatMessage $entity, string $tripId): TripChatMessageResource
    {
        $rawPois = $entity->getPois();
        try {
            $pois = null === $rawPois ? [] : array_map(PoiSuggestionDto::fromArray(...), $rawPois);
        } catch (\InvalidArgumentException|\TypeError) {
            // A corrupted JSONB row (legacy data, manual SQL fix) must not 500
            // the whole history page — surface the turn without its POIs so
            // the rider still sees the conversation. Wrong types (e.g. lat
            // stored as string) surface as \TypeError under strict_types=1,
            // missing keys as \InvalidArgumentException from fromArray.
            $pois = [];
        }

        return new TripChatMessageResource(
            id: $entity->getId()->toRfc4122(),
            // $tripId comes from the URL — using it directly avoids a SELECT to
            // initialise the lazy Doctrine proxy returned by $entity->getTrip().
            tripId: $tripId,
            role: $entity->getRole(),
            content: $entity->getContent(),
            action: $entity->getAction(),
            geoLat: $entity->getGeoLat(),
            geoLon: $entity->getGeoLon(),
            pois: $pois,
            createdAt: $entity->getCreatedAt(),
        );
    }
}
