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
 *
 * The scope is (user, operation, key). Not global, because the key belongs to the client that
 * minted it and two clients picking the same string must not be handed each other's trip. Not
 * per trip, because a creation has no trip yet — which is the whole reason this exists.
 *
 * `$requestDigest` fingerprints the body and does one thing: the same key arriving with a
 * different body is a client contradicting itself, and is answered 409. It never identifies the
 * request — `$key` does that, so two deliberate identical creations both succeed.
 *
 * Read here, written by {@see \App\State\Idempotency::remember()} straight through the
 * connection: the losing insert of a race must not close the entity manager, which a flush would
 * do. So this declares the table and the constraint the guarantee rests on, and hydrates rows —
 * it never builds one.
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

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Column(name: 'operation', length: 128)]
    public string $operation;

    #[ORM\Column(name: 'idempotency_key', length: 255)]
    public string $key;

    #[ORM\Column(name: 'request_digest', length: 32)]
    public string $requestDigest;

    /** The trip the first call created, from which the replayed answer is rebuilt. */
    #[ORM\Column(name: 'resource_id', type: 'uuid')]
    public Uuid $resourceId;
}
