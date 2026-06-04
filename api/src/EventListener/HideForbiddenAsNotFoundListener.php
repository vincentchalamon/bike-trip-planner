<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\TripNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Surfaces object-level authorization denials as 404 instead of 403.
 *
 * Every `security:` expression in this app gates trip access by ownership
 * (`TRIP_VIEW` / `TRIP_EDIT` / `TRIP_DELETE`). A 403 on a trip the caller does
 * not own confirms the trip exists, letting an authenticated attacker enumerate
 * other users' trips by probing UUIDs (403 = exists, 404 = absent). Replacing
 * the denial with the same {@see TripNotFoundException} a missing trip raises
 * makes a foreign trip indistinguishable from a non-existent one (RFC 7231
 * §6.5.4 explicitly allows 404 to hide a forbidden resource; same contract as
 * GitHub on private repos). See ADR-038.
 *
 * The listener runs at priority 2, just before the firewall's own exception
 * listener (priority 1): it swaps the throwable so the firewall no longer sees
 * an access denial, and API Platform renders the canonical 404. The swap is
 * gated on full authentication (mirroring the firewall) so an anonymous caller
 * still gets 401 from the entry point. The replacement carries no `previous`
 * link to the original exception, because the firewall listener walks
 * `getPrevious()` and would otherwise re-detect the wrapped AccessDeniedException.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
final readonly class HideForbiddenAsNotFoundListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private AuthenticationTrustResolverInterface $trustResolver,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof AccessDeniedException) {
            return;
        }

        if (!$this->trustResolver->isFullFledged($this->tokenStorage->getToken())) {
            return;
        }

        $event->setThrowable(new TripNotFoundException());
    }
}
