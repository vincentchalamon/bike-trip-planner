<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdempotencyKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a client already asked for, so asking again produces the same answer (ADR-077).
 *
 * Only creations need this. Everywhere else `If-Match` already is the idempotency key, and a
 * better one, because it is ordered: a replay carries a version the trip has moved past and is
 * refused with 412. A creation has no resource to pin a version on, and replaying it makes a
 * second trip — which nothing in the database would stop.
 *
 * Kept in Postgres, not in the cache: the guarantee is only worth what proves it, and the cache
 * pools fall back to an array adapter under test, one per process.
 */
#[ORM\Entity(repositoryClass: IdempotencyKeyRepository::class)]
#[ORM\Table(name: 'idempotency_key')]
#[ORM\UniqueConstraint(name: 'uniq_idempotency_key', columns: ['user_id', 'operation', 'idempotency_key'])]
#[ORM\Index(name: 'idx_idempotency_key_created_at', columns: ['created_at'])]
class IdempotencyKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $id;

    /**
     * Scoped to the user and the operation, never global: the key belongs to the client that
     * minted it, and two clients picking the same string must not collide. Scoping it to a trip
     * is not available — `POST /trips` has no trip yet, which is the whole reason this exists.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Column(name: 'operation', length: 128)]
    public string $operation;

    #[ORM\Column(name: 'idempotency_key', length: 255)]
    public string $key;

    /**
     * Fingerprint of the request body, and the only thing it is for: the same key sent with a
     * different body is a client contradicting itself, and is answered 409. It never identifies
     * the request — the key does that, so two deliberate identical creations both succeed.
     */
    #[ORM\Column(name: 'request_digest', length: 32)]
    public string $requestDigest;

    /** The trip the first call created, from which the replayed answer is rebuilt. */
    #[ORM\Column(name: 'resource_id', type: 'uuid')]
    public Uuid $resourceId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $operation, string $key, string $requestDigest, Uuid $resourceId)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->operation = $operation;
        $this->key = $key;
        $this->requestDigest = $requestDigest;
        $this->resourceId = $resourceId;
        $this->createdAt = new \DateTimeImmutable();
    }
}
