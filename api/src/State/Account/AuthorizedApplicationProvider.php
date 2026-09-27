<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Account\AuthorizedApplication;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Repository\OAuthGrantRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * The applications this user can still be acted for by.
 *
 * Two records answer the question together, and neither could alone: the grant row knows when
 * the application was let in and when it last did something, the tokens know whether it can
 * still do anything at all. {@see OAuthGrantRepository::findLiveForUser()} is where they meet,
 * and the rule it encodes is the one to keep in mind when reading this screen — **the table
 * carries the dates, the tokens carry the truth**.
 *
 * @implements ProviderInterface<AuthorizedApplication>
 */
final readonly class AuthorizedApplicationProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private OAuthGrantRepository $grants,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<AuthorizedApplication>|AuthorizedApplication|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|AuthorizedApplication|null
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $id = $uriVariables['id'] ?? null;

        if (null === $id) {
            return array_map($this->toResource(...), $this->grants->findLiveForUser($user));
        }

        // Same scoped lookup as the revocation: someone else's identifier and one that never
        // existed are one answer, decided by the query rather than compared here.
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }

        $grant = $this->grants->findLiveOwnedBy($user, Uuid::fromString($id));

        return $grant instanceof OAuthGrant ? $this->toResource($grant) : null;
    }

    private function toResource(OAuthGrant $grant): AuthorizedApplication
    {
        $identifier = $grant->getClient()->getIdentifier();

        return new AuthorizedApplication(
            id: $grant->getId()->toRfc4122(),
            name: $grant->getClient()->getName(),
            // The host, not the whole URL: it is what a person recognises, and the path of a
            // metadata document tells them nothing. Falls back to the identifier when it is not
            // a URL at all — a pre-registered development client, for instance.
            host: parse_url($identifier, \PHP_URL_HOST) ?: $identifier,
            scopes: $grant->getScopes(),
            authorizedAt: $grant->getAuthorizedAt(),
            lastUsedAt: $grant->getLastUsedAt(),
        );
    }
}
