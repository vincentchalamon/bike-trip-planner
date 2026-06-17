<?php

declare(strict_types=1);

namespace App\Entity;

use App\ApiResource\TripRequest;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    /**
     * Optional bring-your-own AI provider (ADR-042): the {@see App\Llm\AiProvider}
     * value the user picked, or null when AI is not configured.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $aiProvider = null;

    /**
     * The user's provider API token stored as ciphertext (see AiTokenEncryptor),
     * never the plaintext: encrypted at the write boundary, decrypted only at
     * call time.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiToken = null;

    /** @var Collection<int, TripRequest> */
    #[ORM\OneToMany(targetEntity: TripRequest::class, mappedBy: 'user', fetch: 'EXTRA_LAZY')]
    private Collection $trips;

    /** @param non-empty-string $email */
    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $email,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->trips = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getEmail(): string
    {
        return $this->email;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    /**
     * Irreversibly anonymises the account: soft-deletes it and replaces the
     * email PII with a non-resolvable placeholder. The roles are dropped so a
     * lingering JWT no longer grants elevated access.
     */
    public function anonymize(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->email = \sprintf('deleted-%s@deleted.invalid', $this->id->toRfc4122());
        $this->roles = [];
        $this->locale = 'fr';
        $this->aiProvider = null;
        $this->aiToken = null;
    }

    public function getAiProvider(): ?string
    {
        return $this->aiProvider;
    }

    public function setAiProvider(?string $aiProvider): self
    {
        $this->aiProvider = $aiProvider;

        return $this;
    }

    /**
     * Ciphertext, or null. Encrypt with AiTokenEncryptor before setting.
     */
    public function getAiToken(): ?string
    {
        return $this->aiToken;
    }

    public function setAiToken(?string $aiToken): self
    {
        $this->aiToken = $aiToken;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /** @return Collection<int, TripRequest> */
    public function getTrips(): Collection
    {
        return $this->trips;
    }

    public function eraseCredentials(): void
    {
        // No credentials to erase (passwordless auth)
    }
}
