<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The 30-minute lifetime of a magic link, whatever the PHP default timezone (CI runs
 * Europe/Paris, the image UTC): `expires_at` is a TIMESTAMP WITHOUT TIME ZONE compared
 * against a UTC "now", so both sides must be written in UTC.
 */
#[ResetDatabase]
final class MagicLinkRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    #[Test]
    public function theStoredExpiryIsThirtyMinutesAfterTheUtcNowTheConsumptionComparesAgainst(): void
    {
        $this->issue($this->repository(new NativeClock()), 'expiry@example.com');

        $minutes = $this->em->getConnection()->fetchOne(
            "SELECT EXTRACT(EPOCH FROM expires_at - (now() AT TIME ZONE 'UTC')) / 60 FROM magic_link",
        );

        self::assertIsNumeric($minutes);
        self::assertEqualsWithDelta(30.0, (float) $minutes, 1.0);
    }

    #[Test]
    public function aLinkIsConsumableUntilItsThirtyMinutesAreUp(): void
    {
        $clock = new MockClock('2026-07-01 10:00:00', 'Europe/Paris');
        $repository = $this->repository($clock);
        $token = $this->issue($repository, 'fresh@example.com');

        $clock->modify('+29 minutes');

        self::assertInstanceOf(User::class, $repository->consumeByToken($token));
    }

    #[Test]
    public function aLinkPastItsThirtyMinutesIsRefused(): void
    {
        $clock = new MockClock('2026-07-01 10:00:00', 'Europe/Paris');
        $repository = $this->repository($clock);
        $token = $this->issue($repository, 'stale@example.com');

        $clock->modify('+31 minutes');

        self::assertNull($repository->consumeByToken($token));
    }

    #[Test]
    public function aPendingLinkBlocksASecondOneOnlyUntilItExpires(): void
    {
        $clock = new MockClock('2026-07-01 10:00:00', 'Europe/Paris');
        $repository = $this->repository($clock);
        $user = new User('pending@example.com');
        $this->em->persist($user);
        $first = $repository->issue($user);
        self::assertInstanceOf(MagicLink::class, $first);
        $repository->save($first);
        $whilePending = $repository->issue($user);
        $clock->modify('+31 minutes');
        $afterExpiry = $repository->issue($user);

        self::assertNull($whilePending);
        self::assertInstanceOf(MagicLink::class, $afterExpiry);
    }

    /**
     * The link is sent before it is stored: one whose email never left must not block the
     * next request for thirty minutes.
     */
    #[Test]
    public function anIssuedLinkIsNotStoredUntilSaved(): void
    {
        $repository = $this->repository(new NativeClock());
        $user = new User('unsent@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $repository->issue($user);
        $this->em->flush();

        self::assertSame(0, $repository->count([]));
        self::assertInstanceOf(MagicLink::class, $repository->issue($user));
    }

    private function repository(ClockInterface $clock): MagicLinkRepository
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof ManagerRegistry);

        return new MagicLinkRepository($registry, new NullLogger(), $clock);
    }

    /**
     * @param non-empty-string $email
     */
    private function issue(MagicLinkRepository $repository, string $email): string
    {
        $user = new User($email);
        $this->em->persist($user);
        $link = $repository->issue($user);
        self::assertInstanceOf(MagicLink::class, $link);
        $repository->save($link);

        $token = $link->getPlainToken();
        self::assertIsString($token);

        return $token;
    }
}
