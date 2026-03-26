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

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    /** @var Collection<int, TripRequest> */
    #[ORM\OneToMany(targetEntity: TripRequest::class, mappedBy: 'user', fetch: 'EXTRA_LAZY')]
    private Collection $trips;

    /** @param non-empty-string $email */
    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $email,
        ?Uuid $id = null
    )
    {
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
