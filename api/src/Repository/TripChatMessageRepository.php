<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Persistence boundary for the long-term chat history.
 *
 * Read methods are intentionally kept out of this PR: the write path proves
 * the dual Redis + PostgreSQL persistence in isolation, and the read endpoint
 * (`GET /trips/{id}/chat-history`) is delivered by issue #459 / PR #471 where
 * the cursor pagination semantics live with the State Provider that consumes
 * them.
 *
 * @extends ServiceEntityRepository<TripChatMessage>
 */
class TripChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripChatMessage::class);
    }
}
