<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenEncryptor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_token')]
#[ORM\UniqueConstraint(name: 'uniq_refresh_token_digest', columns: ['token_digest'])]
#[ORM\Index(name: 'idx_refresh_token_user', columns: ['user_id'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Plaintext token, held in memory only for the request that mints it so it
     * can be re-served to the client without a decrypt round-trip. Never
     * persisted (the row stores {@see $token} encrypted + {@see $tokenDigest}).
     */
    private ?string $plainToken = null;

    /**
     * Digest of the successor token when this one is rotated. Kept (rather than
     * deleting the row) so a reload race that re-sends the pre-rotation token
     * resolves to its successor within the grace window instead of being
     * rejected (#649). Stores the digest, not the token, so the lookup stays on
     * the indexed column.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $replacedByToken = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        // Reversible ciphertext of the token (see RefreshTokenEncryptor).
        #[ORM\Column(type: 'text')]
        private string $token,
        // Deterministic sha256 of the plaintext token, used for lookup.
        #[ORM\Column(length: 64)]
        private string $tokenDigest,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Mints a token from a plaintext: stores it encrypted + digested and keeps
     * the plaintext in memory for immediate re-serving.
     */
    public static function issue(
        User $user,
        RefreshTokenEncryptor $encryptor,
        #[\SensitiveParameter] string $plainToken,
        \DateTimeImmutable $expiresAt,
    ): self {
        $token = new self($user, $encryptor->encrypt($plainToken), RefreshTokenEncryptor::digest($plainToken), $expiresAt);
        $token->plainToken = $plainToken;

        return $token;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * The stored ciphertext. Decrypt with RefreshTokenEncryptor to recover the
     * plaintext, or read {@see getPlainToken()} on a freshly minted token.
     */
    public function getEncryptedToken(): string
    {
        return $this->token;
    }

    public function getTokenDigest(): string
    {
        return $this->tokenDigest;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getReplacedByToken(): ?string
    {
        return $this->replacedByToken;
    }

    /**
     * Rotates this token: point it at its successor (by digest) and cut its life
     * to the grace window, so a reload race that re-sends it still resolves
     * briefly (#649).
     */
    public function replaceWith(string $successorDigest, \DateTimeImmutable $graceExpiresAt): void
    {
        $this->replacedByToken = $successorDigest;
        $this->expiresAt = $graceExpiresAt;
    }

    public function isValid(): bool
    {
        return $this->expiresAt > new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
