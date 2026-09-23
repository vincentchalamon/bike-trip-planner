<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripRoute;
use App\Concurrency\TripVersionEtag;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The route geometry, and the one place in this API where a 304 is honest (ADR-078).
 *
 * The representation here is `{id, stages: [{dayNumber, geometry}]}` and nothing else, and
 * both mutable fields are written only by `storeStages()`, which always bumps `trip.version`.
 * So the version *is* a representation validator for this resource — unlike `/detail`, whose
 * body the eight targeted enrichment writes rewrite without touching the version.
 *
 * The conditional check lives here rather than in a decorator of the read provider on purpose.
 * {@see TripShareRouteProvider} resolves a short code and rejects a revoked share *before*
 * delegating here; a decorator would answer 304 ahead of that and tell a client its copy of a
 * revoked share is still current. Confirming a cached copy is an authorization decision.
 *
 * @implements ProviderInterface<TripRoute>
 */
// Not `final`: TripShareRouteProvider has to take this class rather than the interface, since
// only this signature admits the bare 304, and its unit test has to double it. PHPUnit cannot
// double a final class. Same reason as App\State\Idempotency.
readonly class TripRouteProvider implements ProviderInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRoute|Response
    {
        $id = \is_string($uriVariables['id'] ?? null) ? $uriVariables['id'] : '';

        // The 404 used to come from getStages() answering null. It answers null for exactly
        // one reason — the trip row is missing — which is the same reason getVersion() does,
        // and that one costs a single column instead of the whole stage collection.
        $version = $this->tripStateManager->getVersion($id);
        if (null === $version) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $id));
        }

        $etag = TripVersionEtag::value($version);
        $request = $context['request'] ?? null;

        if ($request instanceof Request && $this->matches($request, $etag)) {
            // Before reading a single row of `stage`: the point of the tag is to skip the
            // work, not just the bytes. AddHeadersProcessor bails on a non-2xx response, so
            // the validator and the Vary it was negotiated under are set here or nowhere
            // (RFC 9110 §15.4.5).
            return new Response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
                'Vary' => 'Accept, Authorization, Origin',
            ]);
        }

        TripVersionEtag::stampValidator($context, $version);

        return new TripRoute(id: $id, stages: $this->tripStateManager->getRouteGeometry($id));
    }

    /**
     * `If-None-Match` uses the weak comparison function (RFC 9110 §13.1.2), but the tag is
     * strong and generated here, so a byte comparison over the comma-separated list is the
     * whole of it. `*` matches any existing representation.
     */
    private function matches(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if (null === $header) {
            return false;
        }

        $candidates = array_map(trim(...), explode(',', $header));

        return \in_array('*', $candidates, true) || \in_array($etag, $candidates, true);
    }
}
