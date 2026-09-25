<?php

declare(strict_types=1);

namespace App\Tests\Integration\State;

use ApiPlatform\Metadata\Post;
use App\Entity\User;
use App\State\Idempotency;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The losing side of the unique index, which is the only reason that index exists (ADR-077).
 *
 * `TripCreationIdempotencyTest` replays sequentially, so it always takes the pre-check branch and
 * never reaches the violated insert. Here the second call is the loser: it must be handed the
 * winner's identifier, and — the part a mock cannot show — Doctrine must still work afterwards,
 * because the calling processor still has a trip to read before it can answer.
 */
#[ResetDatabase]
final class IdempotencyRaceTest extends KernelTestCase
{
    private const string KEY = 'race-key-0123456789';

    #[Test]
    public function theLoserIsHandedTheWinnersResource(): void
    {
        [$idempotency, $user] = $this->boot();
        $operation = new Post(uriTemplate: '/trips');

        $winner = Uuid::v7();
        $loser = Uuid::v7();

        self::assertTrue($winner->equals($idempotency->remember($user, $operation, $winner, [])));
        self::assertTrue($winner->equals($idempotency->remember($user, $operation, $loser, [])));
    }

    /**
     * Persisting the key through the ORM made this fail: `UnitOfWork::commit()` closes the entity
     * manager before the unique violation propagates, so every Doctrine call after the race — the
     * recovery read included — threw, and the loser was answered 500 instead of the winner's trip.
     */
    #[Test]
    public function losingTheRaceLeavesDoctrineUsable(): void
    {
        [$idempotency, $user, $em] = $this->boot();
        $operation = new Post(uriTemplate: '/trips');

        $idempotency->remember($user, $operation, Uuid::v7(), []);
        $idempotency->remember($user, $operation, Uuid::v7(), []);

        $em->clear();

        self::assertInstanceOf(User::class, $em->find(User::class, $user->getId()));
    }

    /**
     * @return array{Idempotency, User, EntityManagerInterface}
     */
    private function boot(): array
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        $user = new User('racer@example.com');
        $em->persist($user);
        $em->flush();

        $request = Request::create('/trips', 'POST');
        $request->headers->set(Idempotency::HEADER, self::KEY);

        $stack = $container->get('request_stack');
        \assert($stack instanceof RequestStack);
        $stack->push($request);

        $idempotency = $container->get(Idempotency::class);
        \assert($idempotency instanceof Idempotency);

        return [$idempotency, $user, $em];
    }
}
