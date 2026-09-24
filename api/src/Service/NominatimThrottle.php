<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The one place that decides how often this deployment may call Nominatim.
 *
 * Its usage policy caps bulk use and bans by IP, and every request from this deployment leaves
 * from the same address, so the limit protects a shared resource rather than any one caller —
 * which is why it is keyed on the user and refuses rather than queues (2026-07 security audit).
 *
 * Extracted because place search and reverse geocoding now live in different classes and both
 * need it. Two copies of a security-relevant rule is one copy too many: a later change — a
 * second bound, a different fallback key, a global cap — has to land in both to be correct,
 * and nothing in either file would point at the other. Same reasoning as lot D's single
 * lock decorator replacing nine hand-written `assertNotLocked()` calls.
 *
 * Called **after** the cache is consulted, in both callers: a repeated search costs the third
 * party nothing, so it should not cost the caller a token either.
 */
final readonly class NominatimThrottle
{
    public function __construct(
        private Security $security,
        #[Autowire(service: 'limiter.geocode')]
        private RateLimiterFactory $limiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException when this user has spent their allowance
     */
    public function throttle(): void
    {
        $key = $this->security->getUser()?->getUserIdentifier() ?? 'anonymous';

        if (!$this->limiter->create($key)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }
}
