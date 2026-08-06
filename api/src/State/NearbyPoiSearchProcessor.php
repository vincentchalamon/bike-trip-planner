<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model\PoiSuggestionDto;
use App\ApiResource\NearbyPoiSearchRequest;
use App\ApiResource\NearbyPoiSearchResponse;
use App\Entity\User;
use App\Geo\GeoPoint;
use App\InRide\NearbyPoiFinder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Handles `POST /trips/{id}/nearby-pois`: runs the AI-free in-ride orchestrator
 * (#933) over the trip's coverage zone and returns the ranked POI suggestions.
 *
 * Pipeline: per-user rate limit -> radius clamp -> {@see NearbyPoiFinder} ->
 * DTO mapping. The trip is resolved (and ownership enforced via `TRIP_VIEW`) by
 * {@see TripRequestProvider} before this processor runs; a missing trip is a 404
 * there. Nothing here mutates the trip.
 *
 * @implements ProcessorInterface<NearbyPoiSearchRequest, NearbyPoiSearchResponse>
 */
final readonly class NearbyPoiSearchProcessor implements ProcessorInterface
{
    public function __construct(
        private NearbyPoiFinder $finder,
        private Security $security,
        #[Autowire(service: 'limiter.nearby_pois')]
        private RateLimiterFactory $nearbyPoisLimiter,
    ) {
    }

    /**
     * @param NearbyPoiSearchRequest $data
     * @param Post                   $operation
     * @param array{id?: string}     $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NearbyPoiSearchResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);
        if (!$this->nearbyPoisLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $tripId = $uriVariables['id'] ?? '';
        $category = $data->category;
        $position = $data->position;
        \assert(null !== $category && null !== $position);

        $appliedRadius = $category->clampRadius($data->radiusMeters);

        $envelope = $this->finder->find(
            $category,
            new GeoPoint($position->lat, $position->lon),
            $data->radiusMeters,
            $tripId,
            $data->stageDay,
        );

        return new NearbyPoiSearchResponse(
            tripId: $tripId,
            category: $category,
            radiusMeters: $appliedRadius,
            totalFound: $envelope['totalFound'],
            capReached: $envelope['capReached'],
            outOfCoverage: $envelope['outOfCoverage'],
            pois: array_map(
                PoiSuggestionDto::fromSuggestion(...),
                $envelope['pois'],
            ),
        );
    }
}
