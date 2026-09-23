<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\HttpOperation;
use App\Entity\IdempotencyKey;
use App\Entity\User;
use App\Repository\IdempotencyKeyRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lets a creation be asked for twice and answered once (ADR-077).
 *
 * Only creations need it. Everywhere else `If-Match` already is the idempotency key, and a
 * better one because it is ordered: a replay carries a version the trip has moved past and is
 * refused with 412. A creation has no resource to pin a version on, so a retry after a dropped
 * response makes a second complete trip — new row, new pipeline — and no constraint stands in
 * the way, since the identifier is minted by the server.
 *
 * Called from the creating processors rather than from a decorator of the write chain. A
 * decorator only sees the trip that was created from certain positions in that chain, and a
 * rule that works from one position is a rule that breaks the day someone inserts another
 * decorator. {@see \App\Tests\Unit\State\IdempotencyCoverageTest} binds the operations carrying
 * the flag to the processors that honour it, so the two cannot drift apart.
 */
// Not `final`: a processor that depends on it has to be unit-testable, and PHPUnit cannot
// double a final class. Same reason as App\Health\RedisHealthClientFactory.
readonly class Idempotency
{
    public const string HEADER = 'Idempotency-Key';

    /** Opaque to the server, which only ever compares it. */
    private const string KEY_PATTERN = '/^[A-Za-z0-9_-]{16,255}$/';

    public function __construct(
        private IdempotencyKeyRepository $keys,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * The identifier this request already produced, or null if it is new.
     *
     * Throws 400 when the header is missing or malformed — not 428, which means "make your
     * request conditional" and would send a client looking for an `If-Match` it cannot supply
     * on a creation. Throws 409 when the same key arrives with a different body, which is a
     * client contradicting itself.
     */
    public function alreadyCreated(User $user, HttpOperation $operation): ?Uuid
    {
        $recorded = $this->keys->findRecorded($user, $this->scope($operation), $this->key());

        if (!$recorded instanceof IdempotencyKey) {
            return null;
        }

        if ($recorded->requestDigest !== $this->digest()) {
            throw new ConflictHttpException(\sprintf('This "%s" was already used for a different request body.', self::HEADER));
        }

        return $recorded->resourceId;
    }

    /**
     * Ties the key to what it created.
     *
     * Written rather than pre-checked: two concurrent calls carrying the same key both get here,
     * and the unique index — not a lookup — is what makes one of them lose. The loser is told
     * which trip won, and answers with that one. A pre-check alone leaves the window between
     * reading and writing wide open, which is the flaw in the share endpoint this is modelled on.
     */
    public function remember(User $user, HttpOperation $operation, Uuid $resourceId): Uuid
    {
        $key = $this->key();
        $scope = $this->scope($operation);

        try {
            $this->entityManager->persist(new IdempotencyKey($user, $scope, $key, $this->digest(), $resourceId));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $recorded = $this->keys->findRecorded($user, $scope, $key);

            return $recorded instanceof IdempotencyKey ? $recorded->resourceId : $resourceId;
        }

        return $resourceId;
    }

    /**
     * The namespace a key is unique within, alongside the user.
     *
     * The operation, not the processor and certainly not the flag that marks it: two endpoints
     * sharing a namespace means a client reusing one key across both is answered 409 on a body
     * that is simply a different request — or worse, handed the other endpoint's trip. Derived
     * here rather than passed in, so the lookup and the write in one processor cannot disagree
     * about it; a mismatch there would make every lookup miss its own row, silently.
     */
    private function scope(HttpOperation $operation): string
    {
        return $operation->getName() ?? $operation->getUriTemplate() ?? $operation::class;
    }

    private function key(): string
    {
        $key = $this->requestStack->getCurrentRequest()?->headers->get(self::HEADER);

        if (null === $key || 1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new BadRequestHttpException(\sprintf('This operation requires an "%s" header: an opaque string of 16 to 255 characters from [A-Za-z0-9_-], minted once per creation and sent again unchanged on every retry of it.', self::HEADER));
        }

        return $key;
    }

    private function digest(): string
    {
        return hash('xxh128', $this->requestStack->getCurrentRequest()?->getContent() ?? '');
    }
}
