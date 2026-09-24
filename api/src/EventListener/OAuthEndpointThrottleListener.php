<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Bounds the two OAuth endpoints (ADR-079).
 *
 * An agent loop is the most likely source of load this system will ever see, and these two
 * doors are the ones it goes through. The token endpoint is anonymous by construction — it
 * authenticates a client with a code and a PKCE verifier, not a user — so it is keyed on the
 * address; the authorization endpoint has a user behind it and is keyed on them.
 *
 * ⚠ A caller whose address cannot be resolved is throttled with everyone else, not exempted.
 * Failing open here is reachable by misconfiguring trusted proxies, and it would remove the
 * guard from exactly the endpoint it was added for: not being able to tell callers apart is
 * a reason to put them in one bucket, not a reason to stop counting.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class OAuthEndpointThrottleListener
{
    public function __construct(
        #[Autowire(service: 'limiter.oauth_token')]
        private RateLimiterFactory $tokenLimiter,
        #[Autowire(service: 'limiter.oauth_authorize')]
        private RateLimiterFactory $authorizeLimiter,
        private Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        [$limiter, $key] = match ($request->attributes->get('_route')) {
            'oauth2_token' => [$this->tokenLimiter, $request->getClientIp() ?? 'unknown'],
            'oauth2_authorize' => [$this->authorizeLimiter, $this->security->getUser()?->getUserIdentifier() ?? 'unknown'],
            default => [null, ''],
        };

        if (!$limiter instanceof RateLimiterFactory) {
            return;
        }

        if (!$limiter->create($key)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }
}
