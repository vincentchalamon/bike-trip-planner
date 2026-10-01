<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Refresh-token rotation: the compare-and-swap that lets one concurrent caller win, and the
 * clock it compares with.
 */
#[CoversClass(RefreshTokenRepository::class)]
#[ResetDatabase]
final class RefreshTokenRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private RefreshTokenRepository $repository;

    private string $timezone;

    #[\Override]
    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();

        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var RefreshTokenRepository $repository */
        $repository = $container->get(RefreshTokenRepository::class);
        $this->repository = $repository;
    }

    #[\Override]
    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    #[Test]
    public function aRotationIssuesAStoredSuccessorAndKeepsThePredecessorForTheGraceWindow(): void
    {
        $existing = $this->repository->createForUser($this->user());

        $successor = $this->repository->rotate($existing, 30);

        self::assertInstanceOf(RefreshToken::class, $successor);
        self::assertSame($successor->getTokenDigest(), $existing->getReplacedByToken());
        self::assertEqualsWithDelta(time() + 30, $existing->getExpiresAt()->getTimestamp(), 2);

        $this->em->clear();
        $stored = $this->repository->findValidByDigest($successor->getTokenDigest());
        self::assertInstanceOf(RefreshToken::class, $stored);
        $predecessor = $this->repository->findValidByDigest($existing->getTokenDigest());
        self::assertInstanceOf(RefreshToken::class, $predecessor);
        self::assertSame($successor->getTokenDigest(), $predecessor->getReplacedByToken());
    }

    /**
     * A concurrent request rotated first: the loser stores nothing and follows the winner's
     * successor instead of minting a second one.
     */
    #[Test]
    public function theLoserOfARaceFollowsTheWinnersSuccessor(): void
    {
        $user = $this->user();
        $existing = $this->repository->createForUser($user);
        $winner = $this->repository->createForUser($user);
        $this->em->getConnection()->executeStatement(
            'UPDATE refresh_token SET replaced_by_token = :digest WHERE id = :id',
            ['digest' => $winner->getTokenDigest(), 'id' => $existing->getId()->toRfc4122()],
        );

        $followed = $this->repository->rotate($existing, 30);

        self::assertInstanceOf(RefreshToken::class, $followed);
        self::assertSame($winner->getTokenDigest(), $followed->getTokenDigest());
        self::assertSame(2, $this->repository->count([]));
    }

    /**
     * `expires_at` is written in PHP's zone and carries none. The claim used to compare it
     * with the database's NOW(), which runs in the session's zone: west of it, a token PHP
     * holds valid for another hour was already past expiry for the database, so the rotation
     * claimed nothing and the session ended in a 401.
     */
    #[Test]
    public function aTokenPhpHoldsValidCanBeRotatedWhateverThePhpTimezone(): void
    {
        date_default_timezone_set('Pacific/Honolulu');

        $encryptor = self::getContainer()->get(RefreshTokenEncryptor::class);
        \assert($encryptor instanceof RefreshTokenEncryptor);
        $existing = RefreshToken::issue($this->user(), $encryptor, bin2hex(random_bytes(64)), new \DateTimeImmutable('+1 hour'));
        $this->em->persist($existing);
        $this->em->flush();
        self::assertTrue($existing->isValid());

        $successor = $this->repository->rotate($existing, 30);

        self::assertInstanceOf(RefreshToken::class, $successor);
        self::assertSame($successor->getTokenDigest(), $existing->getReplacedByToken());
    }

    private function user(): User
    {
        $user = new User('rider-'.bin2hex(random_bytes(4)).'@example.com');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
