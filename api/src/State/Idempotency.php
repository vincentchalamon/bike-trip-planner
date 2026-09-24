<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\HttpOperation;
use App\Entity\IdempotencyKey;
use App\Entity\User;
use App\Repository\IdempotencyKeyRepository;
use App\State\Mcp\McpArguments;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\ClockInterface;
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
 *
 * Two transports carry the key and they do not carry it alike. HTTP has a header and a client
 * that knows to mint one; an MCP tool call has neither, and the guard is inverted there: the
 * key is optional and the server derives one, because a nonce is exactly the kind of thing a
 * model invents badly ({@see self::derived()}). That asymmetry with `If-Match` — required
 * there, derived here — is deliberate: a version is an assertion only the caller can make,
 * a key is bookkeeping only the server can be trusted with.
 */
// Not `final`: a processor that depends on it has to be unit-testable, and PHPUnit cannot
// double a final class. Same reason as App\Health\RedisHealthClientFactory.
readonly class Idempotency
{
    public const string HEADER = 'Idempotency-Key';

    /** The same thing, named as an argument where there is no header to put it in. */
    public const string ARGUMENT = 'idempotencyKey';

    /** Opaque to the server, which only ever compares it. */
    private const string KEY_PATTERN = '/^[A-Za-z0-9_-]{16,255}$/';

    /**
     * How long a derived key stands for "the same call again" (seconds).
     *
     * Short on purpose: the derivation makes two identical calls indistinguishable, so the
     * window is exactly the span over which that identity is a retry rather than a second
     * intent. Asking for the same trip twice within five minutes is a replay; asking again
     * tomorrow is a decision.
     */
    private const int DERIVED_WINDOW = 300;

    public function __construct(
        private IdempotencyKeyRepository $keys,
        private Connection $connection,
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The identifier this request already produced, or null if it is new.
     *
     * Throws 400 when the key is missing or malformed — not 428, which means "make your
     * request conditional" and would send a client looking for an `If-Match` it cannot supply
     * on a creation. Throws 409 when the same key arrives with a different body, which is a
     * client contradicting itself.
     *
     * @param array<string, mixed> $context
     */
    public function alreadyCreated(User $user, HttpOperation $operation, array $context): ?Uuid
    {
        $scope = $this->scope($operation);
        $digest = $this->digest($context);

        foreach ($this->candidateKeys($user, $operation, $context) as $key) {
            $recorded = $this->keys->findRecorded($user, $scope, $key);

            if (!$recorded instanceof IdempotencyKey) {
                continue;
            }

            if ($recorded->requestDigest !== $digest) {
                throw new ConflictHttpException(\sprintf('This "%s" was already used for a different request body.', McpArguments::isToolCall($context) ? self::ARGUMENT : self::HEADER));
            }

            return $recorded->resourceId;
        }

        return null;
    }

    /**
     * Ties the key to what it created, and returns the identifier the caller must answer with.
     *
     * Written rather than pre-checked: two concurrent calls carrying the same key both get here,
     * and the unique index — not a lookup — is what makes one of them lose. The loser is told
     * which trip won, and answers with that one. A pre-check alone leaves the window between
     * reading and writing wide open, which is the flaw in the share endpoint this is modelled on.
     *
     * Inserted through the connection rather than persisted through the ORM, because the losing
     * insert is the whole point and `EntityManager::flush()` does not survive it:
     * `UnitOfWork::commit()` closes the entity manager in its `finally` before the exception
     * propagates (orm/src/UnitOfWork.php:470-472). Every Doctrine read after that — the one below,
     * and everything the calling processor still has to do — would throw `EntityManagerClosed`,
     * so the race this method exists to handle would answer 500. The connection is only rolled
     * back, never closed. It also keeps the flush from carrying along whatever else the unit of
     * work happens to hold.
     *
     * @param array<string, mixed> $context
     */
    public function remember(User $user, HttpOperation $operation, Uuid $resourceId, array $context): Uuid
    {
        // The current key, never the previous window's: this only runs when the lookup found
        // nothing, so there is no earlier row to extend and recording under the older bucket
        // would shorten the retry window of the call being made right now.
        $key = $this->candidateKeys($user, $operation, $context)[0];
        $scope = $this->scope($operation);

        // Outside any transaction: a violated INSERT aborts the enclosing one in Postgres, and
        // the recovery read below would fail with it. Both creating processors commit first.
        \assert(!$this->connection->isTransactionActive());

        try {
            $this->connection->insert('idempotency_key', [
                'id' => Uuid::v7(),
                'user_id' => $user->getId(),
                'operation' => $scope,
                'idempotency_key' => $key,
                'request_digest' => $this->digest($context),
                'resource_id' => $resourceId,
                'created_at' => new \DateTimeImmutable(),
            ], [
                'id' => UuidType::NAME,
                'user_id' => UuidType::NAME,
                'resource_id' => UuidType::NAME,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
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

    /**
     * The keys that could stand for this call, most recent first.
     *
     * More than one only on the MCP transport, and only when the key is derived: a derivation
     * needs a clock to bound it, and a clock cut into windows puts a retry made a second past
     * a boundary in the next bucket. Looking the previous one up as well costs one indexed
     * read and removes a failure mode that would have been invisible and intermittent — the
     * worst combination, since the symptom is a second trip rather than an error.
     *
     * @param array<string, mixed> $context
     *
     * @return non-empty-list<string>
     */
    private function candidateKeys(User $user, HttpOperation $operation, array $context): array
    {
        if (!McpArguments::isToolCall($context)) {
            return [$this->validated($this->requestStack->getCurrentRequest()?->headers->get(self::HEADER), self::HEADER, 'header')];
        }

        $explicit = McpArguments::from($context)->control(self::ARGUMENT);

        if (null !== $explicit && '' !== $explicit) {
            return [$this->validated(\is_string($explicit) ? $explicit : null, self::ARGUMENT, 'argument')];
        }

        $window = intdiv($this->clock->now()->getTimestamp(), self::DERIVED_WINDOW);

        return [
            $this->derived($user, $operation, $context, $window),
            $this->derived($user, $operation, $context, $window - 1),
        ];
    }

    /**
     * A key the server works out instead of asking for one.
     *
     * Requiring a nonce from a model has two failure modes and both are worse than having no
     * guard. It reuses one literal string across two different trips, and every creation after
     * the first is answered 409 for a body that is simply a different request; or it mints a
     * fresh one on each attempt, including on the retry, and the key protects nothing at all.
     * Deriving it from what the call actually is — who, which tool, which arguments — makes two
     * identical calls the same call, which is what a retry is, and it is the server that says
     * so rather than the caller.
     *
     * Not a secret and not built like one: it is scoped to the user, so guessing another
     * caller's derived key gains nothing, and an HTTP client that sent this exact string as its
     * header could at worst be handed its own earlier trip.
     *
     * @param array<string, mixed> $context
     */
    private function derived(User $user, HttpOperation $operation, array $context, int $window): string
    {
        return 'derived-'.hash('xxh128', implode("\0", [
            $user->getId()->toRfc4122(),
            $this->scope($operation),
            $this->digest($context),
            (string) $window,
        ]));
    }

    private function validated(?string $key, string $name, string $carrier): string
    {
        if (null === $key || 1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new BadRequestHttpException(\sprintf('This operation requires an "%s" %s: an opaque string of 16 to 255 characters from [A-Za-z0-9_-], minted once per creation and sent again unchanged on every retry of it.', $name, $carrier));
        }

        return $key;
    }

    /** @param array<string, mixed> $context */
    private function digest(array $context): string
    {
        if (McpArguments::isToolCall($context)) {
            return hash('xxh128', json_encode(McpArguments::from($context)->canonical(), \JSON_THROW_ON_ERROR));
        }

        return hash('xxh128', $this->requestStack->getCurrentRequest()?->getContent() ?? '');
    }
}
