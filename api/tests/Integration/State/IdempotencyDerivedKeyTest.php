<?php

declare(strict_types=1);

namespace App\Tests\Integration\State;

use ApiPlatform\Metadata\McpTool;
use App\Entity\User;
use App\Repository\IdempotencyKeyRepository;
use App\State\Idempotency;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The key a caller never sent, which is the only kind an agent reliably produces.
 *
 * On HTTP the key is required and the client mints it. Asking a model for the same nonce has
 * two failure modes and both are worse than no guard at all — one literal string reused across
 * two trips (409 forever after), or a fresh one on every attempt (a guard that guards nothing).
 * So on the MCP transport the server derives it instead, and these are the properties that
 * derivation has to hold: identical calls are one call, different calls are not, key order in
 * the JSON is not part of "identical", and a retry landing just past a window boundary is still
 * a retry.
 */
#[ResetDatabase]
final class IdempotencyDerivedKeyTest extends KernelTestCase
{
    private const string TOOL = 'create_trip';

    #[Test]
    public function twoIdenticalCallsAreOneCall(): void
    {
        [$idempotency, $user] = $this->boot();
        $call = $this->toolCall(['sourceUrl' => 'https://www.komoot.com/tour/1']);

        $first = Uuid::v7();
        self::assertTrue($first->equals($idempotency->remember($user, $this->tool(), $first, $call)));

        $replay = $idempotency->alreadyCreated($user, $this->tool(), $call);
        self::assertInstanceOf(Uuid::class, $replay);
        self::assertTrue($first->equals($replay));
    }

    /**
     * Nothing obliges a client to re-serialise its arguments in the order it used the first
     * time, and a retry that hashes differently is a second trip.
     */
    #[Test]
    public function theOrderOfTheArgumentsIsNotPartOfTheCall(): void
    {
        [$idempotency, $user] = $this->boot();

        $first = Uuid::v7();
        $idempotency->remember($user, $this->tool(), $first, $this->toolCall([
            'sourceUrl' => 'https://www.komoot.com/tour/1',
            'title' => 'Vercors',
        ]));

        $replay = $idempotency->alreadyCreated($user, $this->tool(), $this->toolCall([
            'title' => 'Vercors',
            'sourceUrl' => 'https://www.komoot.com/tour/1',
        ]));

        self::assertInstanceOf(Uuid::class, $replay);
        self::assertTrue($first->equals($replay));
    }

    /** A second, different request is a second trip — the guard must not swallow it. */
    #[Test]
    public function aDifferentCallIsADifferentCall(): void
    {
        [$idempotency, $user] = $this->boot();

        $idempotency->remember($user, $this->tool(), Uuid::v7(), $this->toolCall(['sourceUrl' => 'https://www.komoot.com/tour/1']));

        self::assertNull($idempotency->alreadyCreated($user, $this->tool(), $this->toolCall(['sourceUrl' => 'https://www.komoot.com/tour/2'])));
    }

    /**
     * A derivation needs a clock, and a clock cut into windows puts a retry made one second
     * past a boundary in the next bucket. The lookup reads the previous one too, so the failure
     * this would otherwise produce — intermittent, silent, and costing a duplicate trip rather
     * than an error — cannot happen.
     */
    #[Test]
    public function aRetryJustPastAWindowBoundaryIsStillARetry(): void
    {
        $clock = new MockClock('2026-09-24 12:00:00');
        [$idempotency, $user] = $this->boot($clock);
        $call = $this->toolCall(['sourceUrl' => 'https://www.komoot.com/tour/1']);

        $first = Uuid::v7();
        $idempotency->remember($user, $this->tool(), $first, $call);

        // Enough to land in the next bucket whatever the offset within the current one.
        $clock->sleep(301);

        $replay = $idempotency->alreadyCreated($user, $this->tool(), $call);
        self::assertInstanceOf(Uuid::class, $replay);
        self::assertTrue($first->equals($replay));
    }

    /** Past the window the same call is a new intent, not a retry. */
    #[Test]
    public function theWindowDoesEnd(): void
    {
        $clock = new MockClock('2026-09-24 12:00:00');
        [$idempotency, $user] = $this->boot($clock);
        $call = $this->toolCall(['sourceUrl' => 'https://www.komoot.com/tour/1']);

        $idempotency->remember($user, $this->tool(), Uuid::v7(), $call);

        $clock->sleep(3600);

        self::assertNull($idempotency->alreadyCreated($user, $this->tool(), $call));
    }

    /** An agent that does mint a key keeps it: the derivation is a fallback, not a policy. */
    #[Test]
    public function anExplicitKeyWins(): void
    {
        [$idempotency, $user] = $this->boot();

        $first = Uuid::v7();
        $idempotency->remember($user, $this->tool(), $first, $this->toolCall([
            'sourceUrl' => 'https://www.komoot.com/tour/1',
            'idempotencyKey' => 'agent-minted-key-0001',
        ]));

        // Same key, different arguments: the caller contradicting itself is still caught.
        $replay = $idempotency->alreadyCreated($user, $this->tool(), $this->toolCall([
            'sourceUrl' => 'https://www.komoot.com/tour/1',
            'idempotencyKey' => 'agent-minted-key-0001',
        ]));

        self::assertInstanceOf(Uuid::class, $replay);
        self::assertTrue($first->equals($replay));
    }

    #[Test]
    public function anExplicitKeyThatIsNotAKeyIsRefused(): void
    {
        [$idempotency, $user] = $this->boot();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/"idempotencyKey" argument/');

        $idempotency->alreadyCreated($user, $this->tool(), $this->toolCall(['idempotencyKey' => 'short']));
    }

    private function tool(): McpTool
    {
        return new McpTool(name: self::TOOL, uriTemplate: '/trips');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{mcp_data: array<string, mixed>}
     */
    private function toolCall(array $arguments): array
    {
        return ['mcp_data' => $arguments];
    }

    /**
     * Built by hand rather than pulled from the container: the clock is the point of half
     * these tests, and the container's is the real one.
     *
     * @return array{Idempotency, User}
     */
    private function boot(?MockClock $clock = null): array
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        $user = new User('deriver@example.com');
        $em->persist($user);
        $em->flush();

        $keys = $container->get(IdempotencyKeyRepository::class);
        \assert($keys instanceof IdempotencyKeyRepository);
        $connection = $container->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $stack = $container->get('request_stack');
        \assert($stack instanceof RequestStack);

        return [new Idempotency($keys, $connection, $stack, $clock ?? new MockClock()), $user];
    }
}
