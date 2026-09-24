<?php

declare(strict_types=1);

namespace App\EventListener;

use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\OAuthClient;
use App\Security\OAuth\ClientMetadata;
use App\Security\OAuth\ClientMetadataRejected;
use App\Security\OAuth\ClientMetadataResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Materialises a client that named itself by a URL, so league can find it (ADR-079).
 *
 * The bundle looks a client up through `ClientManagerInterface::find()` and throws when it
 * returns null — unconditionally, in `AuthorizationRequestResolveEventFactoryTrait`. An
 * in-memory client would not be enough either: `oauth2_access_token`,
 * `oauth2_authorization_code` and `oauth2_refresh_token` each carry a foreign key to
 * `oauth2_client` with ON DELETE CASCADE, so a persisted token needs a persisted client.
 *
 * ⚠ This runs on `/oauth/authorize` ONLY, and that is a security boundary rather than an
 * optimisation. `/oauth/token` is PUBLIC_ACCESS: resolving there would put an outbound
 * request to an attacker-named host behind no authentication at all. It does not need to —
 * the client was persisted by the authorization leg, and the token endpoint finds it in the
 * database like any other.
 */
// Priority 7: just AFTER the firewall, which sits at 8. Running before it would mean
// fetching a third-party URL on behalf of nobody — and an unauthenticated request never
// reaches here at all, because the access listener throws inside the firewall.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class ClientIdMetadataDocumentListener
{
    public function __construct(
        private ClientMetadataResolver $resolver,
        private ClientManagerInterface $clients,
        private Security $security,
        #[Autowire(service: 'limiter.oauth_client_metadata_user')]
        private RateLimiterFactory $perUser,
        #[Autowire(service: 'limiter.oauth_client_metadata_host')]
        private RateLimiterFactory $perHost,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ('oauth2_authorize' !== $request->attributes->get('_route')) {
            return;
        }

        $clientId = (string) $request->query->get('client_id', '');
        if (!str_starts_with($clientId, 'https://')) {
            return;
        }

        if ($this->clients->find($clientId) instanceof ClientInterface) {
            // Already known. Re-reading the document on every authorization would hand a
            // third party a request per attempt; the resolver's own cache expiry is what
            // brings changes back in.
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            return;
        }

        try {
            $this->throttle($user->getUserIdentifier(), $clientId);
            $metadata = $this->resolver->resolve($clientId);
        } catch (ClientMetadataRejected) {
            // One answer for every refusal. The reason is in the log; telling the caller
            // whether the host timed out, resolved to a private address or answered badly
            // would turn this endpoint into a network probe.
            $event->setResponse(new JsonResponse([
                'error' => 'invalid_client',
                'error_description' => 'The client identifier could not be resolved.',
            ], Response::HTTP_BAD_REQUEST));

            return;
        }

        $this->persist($metadata);
    }

    private function throttle(string $userIdentifier, string $clientId): void
    {
        if (!$this->perUser->create($userIdentifier)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $host = parse_url($clientId, \PHP_URL_HOST);
        if (!$this->perHost->create(\is_string($host) ? $host : 'unknown')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }

    private function persist(ClientMetadata $metadata): void
    {
        $client = new OAuthClient($metadata->clientName, $metadata->clientId, null);
        $client->setRedirectUris(...array_map(
            static fn (string $uri): RedirectUri => new RedirectUri($uri),
            $metadata->redirectUris,
        ));
        // Public client, no secret, and the two grants an MCP client uses. Plain-text PKCE
        // is refused explicitly rather than left to a default that could move.
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setAllowPlainTextPkce(false);

        try {
            $this->clients->save($client);
        } catch (UniqueConstraintViolationException) {
            // Two authorizations for the same new client at once. The other one won; both
            // want the same row, so there is nothing to reconcile.
        }
    }
}
