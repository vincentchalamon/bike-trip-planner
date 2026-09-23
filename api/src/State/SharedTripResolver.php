<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The one door into a shared trip: resolve the short code, or answer 404.
 *
 * Five anonymous operations reach a trip this way, through four providers that each repeated
 * the same ten lines. That mattered once there was something to add to them: the whole `/s/*`
 * surface had no rate limiter at all, while `/s/{shortCode}` runs the heaviest read in the API
 * — the full stage aggregate plus the alert rendering — with no token of any kind.
 *
 * Calling it an unauthenticated denial of service would be inflation: a caller needs a valid
 * short code, which is 48 bits of entropy. But a share link is public by destination, so the
 * cost of holding one is the cost of anyone the owner sent it to, and per-IP throttling is the
 * standard answer to that.
 *
 * Revocation lives here too — `findByShortCode()` filters on `deletedAt IS NULL` — which is why
 * nothing upstream of this resolver may answer on a share's behalf, a conditional 304 included.
 */
final readonly class SharedTripResolver
{
    public function __construct(
        private TripShareRepositoryInterface $tripShareRepository,
        private RequestStack $requestStack,
        #[Autowire(service: 'limiter.shared_trip')]
        private RateLimiterFactory $limiter,
    ) {
    }

    /**
     * @return string the shared trip's identifier
     */
    public function resolve(string $shortCode): string
    {
        $this->throttle();

        $share = '' !== $shortCode ? $this->tripShareRepository->findByShortCode($shortCode) : null;
        if (!$share instanceof TripShare) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        $trip = $share->getTrip();
        if (!$trip instanceof TripRequest) {
            throw new NotFoundHttpException('Shared trip not found.');
        }

        return (string) $trip->id;
    }

    /**
     * Keyed on the caller, not on the code: throttling per short code would let one client
     * walk a list of them, and there is no account to key on.
     *
     * An unresolvable client IP falls back to a shared bucket rather than skipping the check.
     * Not being able to tell callers apart is a reason to throttle them together, not a reason
     * to stop throttling — and the case is reachable by misconfiguring trusted proxies, which
     * would silently remove the guard from the very endpoint it was added for. Same fallback
     * as every other limiter here ({@see \App\Controller\HealthController},
     * {@see AccessRequestCreateProcessor}).
     */
    private function throttle(): void
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        if (!$this->limiter->create($ip)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }
}
