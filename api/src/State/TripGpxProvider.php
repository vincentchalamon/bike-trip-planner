<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Exception\TripNotFoundException;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves `GET /trips/{id}` — the canonical read of a trip, in JSON-LD, GPX or FIT (ADR-074).
 *
 * It used to serve the two export formats only, and to return a bare `new Trip($id)` because
 * that is all the GPX and FIT normalizers need. The address itself answered 406 to
 * `application/ld+json`, so the `@id` every write response hands out was not dereferenceable.
 * Declaring the format was the easy half; the body is this class.
 *
 * `computationStatus` and `isLocked` are what the write responses already carry, so the
 * canonical read carries the same. The export normalizers ignore both and reload the stages
 * themselves.
 *
 * @implements ProviderInterface<Trip>
 */
final readonly class TripGpxProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private TripLocker $tripLocker,
    ) {
    }

    /**
     * @param array{id?: string}   $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $id = $uriVariables['id'] ?? '';

        $request = $this->tripStateManager->getRequest($id);
        if (!$request instanceof TripRequest) {
            throw new TripNotFoundException();
        }

        // The two reads do not have the same prerequisites. A file with no stages in it is not
        // a file, so the export still refuses; but a trip whose stages have not been computed
        // yet is a perfectly ordinary trip, and its address has to answer — `POST /trips`
        // hands out that `@id` before any stage exists.
        if ('jsonld' !== $this->formatOf($context) && null === $this->tripStateManager->getStages($id)) {
            throw new TripNotFoundException();
        }

        return new Trip(
            id: $id,
            computationStatus: $this->computationTracker->getStatuses($id) ?? [],
            isLocked: $this->tripLocker->isLocked($request),
        );
    }

    /** @param array<string, mixed> $context */
    private function formatOf(array $context): string
    {
        $request = $context['request'] ?? null;

        return $request instanceof Request ? ($request->getRequestFormat('jsonld') ?? 'jsonld') : 'jsonld';
    }
}
