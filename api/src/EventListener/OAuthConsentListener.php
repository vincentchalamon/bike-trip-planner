<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\OAuth\ConsentRecord;
use App\Security\OAuth\ConsentStore;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * The decision point of the authorization flow (ADR-079).
 *
 * league asks this listener one question — approved or denied — and offers a third answer:
 * a response of our own, which is how the user gets asked at all. So the endpoint is
 * traversed twice. The first time there is no decision on file, and the browser is sent to
 * a page in the PWA. The second time the decision is there, and the request completes.
 *
 * Nothing is added to the returning URL. The handle is derived from the request itself
 * ({@see ConsentStore::handle()}), so the second leg recomputes it from what it already
 * holds — and a client that changes an argument between the two legs simply finds no
 * decision and is asked again.
 */
final readonly class OAuthConsentListener
{
    /**
     * The width of `oauth2_access_token.user_identifier`, which holds the email. The column
     * belongs to the bundle's own mapping and a migration widening it would be undone by the
     * next generated diff, so the account is refused here instead of failing on an INSERT
     * at the token endpoint, after consent, with the browser already gone.
     */
    private const int USER_IDENTIFIER_MAX_LENGTH = 128;

    public function __construct(
        private ConsentStore $consents,
        private RequestStack $requestStack,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl,
    ) {
    }

    #[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $user = $event->getUser();

        if (\strlen($user->getUserIdentifier()) > self::USER_IDENTIFIER_MAX_LENGTH) {
            $event->setResponse(new JsonResponse([
                'error' => 'server_error',
                'error_description' => 'This account cannot be granted to an application.',
            ], Response::HTTP_BAD_REQUEST));

            return;
        }

        $scopes = array_values(array_map(strval(...), $event->getScopes()));

        // An authorization request that names no scope is refused rather than defaulted.
        //
        // league resolves an empty request to the client's scope list, but it does so at
        // FINALIZE time — `ScopeRepository::setupScopes()`, on the way to issuing the token,
        // long after this screen. `AuthorizationRequest::getScopes()` here is still empty.
        // Consenting to an empty list and receiving a token that carries permissions is the
        // one outcome no screen can make honest, so the request does not get that far.
        if ([] === $scopes) {
            $event->setResponse(new JsonResponse([
                'error' => 'invalid_scope',
                'error_description' => 'An authorization request must name the scopes it asks for.',
            ], Response::HTTP_BAD_REQUEST));

            return;
        }

        $handle = $this->consents->handle(
            $user->getUserIdentifier(),
            $event->getClient()->getIdentifier(),
            $scopes,
            $event->getRedirectUri(),
            $event->getCodeChallenge(),
        );

        $decided = $this->consents->consume($handle);

        if (null !== $decided?->approved) {
            $event->resolveAuthorization($decided->approved
                ? AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED
                : AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);

            return;
        }

        $this->consents->put($handle, new ConsentRecord(
            userId: $user->getUserIdentifier(),
            clientId: $event->getClient()->getIdentifier(),
            clientName: $event->getClient()->getName(),
            redirectUri: $event->getRedirectUri(),
            // What league validated, which is a subset of what the client is allowed — not
            // the raw query string. The screen renders this and only this.
            scopes: $scopes,
            // Relative on purpose. The PWA assigns it to window.location, which resolves it
            // against the origin it is already on — the same one that serves this endpoint,
            // since a single Caddy routes both by path. An absolute URL here would have to
            // be built from a request header.
            continueUrl: $this->requestStack->getCurrentRequest()?->getRequestUri() ?? '/oauth/authorize',
        ));

        $event->setResponse(new RedirectResponse(
            rtrim($this->frontendUrl, '/').'/oauth/consent/'.$handle,
        ));
    }
}
