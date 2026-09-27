<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthGrantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a user remembers about an agent they let in — not what that agent may do.
 *
 * The tokens are the grant. This row is the story around them: when the application was first
 * let in, and when it last did something with that. Neither question can be answered from the
 * token tables, which carry one column each beyond their identity — `expiry`, a future instant
 * — and are rewritten every fifteen minutes by rotation. So the durable facts live here and
 * **the truth stays with the tokens**: the account screen lists a row only while a live,
 * unrevoked token still backs it ({@see OAuthGrantRepository::findLiveForUser}).
 * A row on its own proves nothing; it never did.
 *
 * Keyed on the user ENTITY, deliberately, where the bundle's tables key on the email
 * (`user_identifier`). An address changes; a person does not. That difference is what lets this
 * row survive an email change — and it is also why the revocation that accompanies one
 * ({@see \App\State\Account\VerifyEmailChangeProcessor}) has to be written by hand: the tokens
 * that carry the old address stop resolving to anyone.
 *
 * `revoked_at` is a tombstone rather than a deletion, and the reason is a race: a refresh in
 * flight re-issues a token a few milliseconds after a revocation commits, and the write at
 * issuance would recreate a deleted row with a fresh `authorized_at` — an application the user
 * just cut off, listed as newly authorised. The partial unique index (one live row per user and
 * client) lets a genuine re-authorisation insert a new row beside the old one.
 */
#[ORM\Entity(repositoryClass: OAuthGrantRepository::class)]
#[ORM\Table(name: 'oauth_grant')]
#[ORM\Index(name: 'idx_oauth_grant_user', columns: ['user_id'])]
class OAuthGrant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /**
     * The first time this application was let in, and it is never rewritten.
     *
     * A later authorisation that widens the scopes updates {@see $scopes} and leaves this
     * alone: the user opened the door on that day, and being told a later date would hide it.
     */
    #[ORM\Column]
    private \DateTimeImmutable $authorizedAt;

    /**
     * When a tool call or a resource read last came in under this grant — not when a token was
     * issued or refreshed, which says nothing about use.
     *
     * Null until the application does something. An agent that connects, lists the tools and
     * leaves reads as never used, which is the intended reading: the screen is about what was
     * done with the access.
     *
     * Written by a bulk UPDATE rather than through this object ({@see \App\Security\OAuth\McpGrantUsage}),
     * which is why nothing here ever assigns it: the write happens on a path that must not load
     * an entity, and is throttled to one statement per window. Hence the ignore below — the
     * annotation has to sit against the property, after the attribute, to attach to it.
     */
    #[ORM\Column(nullable: true)]
    /** @phpstan-ignore property.unusedType */
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * Likewise: {@see \App\Security\OAuth\GrantRevoker} stamps it in the same transaction as
     * the token updates, in DQL.
     */
    #[ORM\Column(nullable: true)]
    /** @phpstan-ignore property.unusedType */
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        /**
         * The client's own identifier: the HTTPS URL its metadata document is served from.
         * Third-party text, and the only part of a client's identity it cannot forge.
         */
        #[ORM\ManyToOne(targetEntity: OAuthClient::class)]
        #[ORM\JoinColumn(name: 'client_identifier', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
        private OAuthClient $client,
        /** Space-separated, as granted — not as the client declares them today. */
        #[ORM\Column(type: 'text')]
        private string $scopes,
        ?\DateTimeImmutable $authorizedAt = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->authorizedAt = $authorizedAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return array_values(array_filter(explode(' ', $this->scopes), static fn (string $scope): bool => '' !== $scope));
    }

    public function getAuthorizedAt(): \DateTimeImmutable
    {
        return $this->authorizedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
