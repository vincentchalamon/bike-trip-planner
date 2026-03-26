<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PurgeExpiredTokensCommandTest extends ApiTestCase
{
    use Factories;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function createCommandTester(): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:purge-expired-tokens');

        return new CommandTester($command);
    }

    #[Test]
    public function purgesExpiredRefreshTokens(): void
    {
        $em = $this->getEntityManager();

        $user = new User('purge@example.com');
        $em->persist($user);

        $expiredToken = new RefreshToken(
            $user,
            'expired-token-abc',
            new \DateTimeImmutable('-1 day'),
        );
        $em->persist($expiredToken);
        $em->flush();

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Purged 1 expired refresh token', $tester->getDisplay());

        /** @var RefreshTokenRepository $repo */
        $repo = self::getContainer()->get(RefreshTokenRepository::class);
        $remaining = $repo->findAll();
        $this->assertCount(0, $remaining);
    }

    #[Test]
    public function keepsValidRefreshTokens(): void
    {
        $em = $this->getEntityManager();

        $user = new User('keep@example.com');
        $em->persist($user);

        $validToken = new RefreshToken(
            $user,
            'valid-token-xyz',
            new \DateTimeImmutable('+30 days'),
        );
        $em->persist($validToken);
        $em->flush();

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Purged 0 expired refresh token', $tester->getDisplay());

        /** @var RefreshTokenRepository $repo */
        $repo = self::getContainer()->get(RefreshTokenRepository::class);
        $remaining = $repo->findAll();
        $this->assertCount(1, $remaining);
    }

    #[Test]
    public function purgesOnlyExpiredAndKeepsValid(): void
    {
        $em = $this->getEntityManager();

        $user = new User('mixed@example.com');
        $em->persist($user);

        $expiredToken = new RefreshToken(
            $user,
            'expired-one',
            new \DateTimeImmutable('-2 days'),
        );
        $em->persist($expiredToken);

        $validToken = new RefreshToken(
            $user,
            'still-valid',
            new \DateTimeImmutable('+15 days'),
        );
        $em->persist($validToken);
        $em->flush();

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Purged 1 expired refresh token', $tester->getDisplay());

        /** @var RefreshTokenRepository $repo */
        $repo = self::getContainer()->get(RefreshTokenRepository::class);
        $remaining = $repo->findAll();
        $this->assertCount(1, $remaining);
        $this->assertSame('still-valid', $remaining[0]->getToken());
    }

    #[Test]
    public function handlesNoExpiredTokensGracefully(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Purged 0 expired refresh token', $tester->getDisplay());
    }

    #[Test]
    public function purgesMultipleExpiredTokensFromDifferentUsers(): void
    {
        $em = $this->getEntityManager();

        $user1 = new User('user1@example.com');
        $em->persist($user1);

        $user2 = new User('user2@example.com');
        $em->persist($user2);

        $expired1 = new RefreshToken(
            $user1,
            'expired-user1',
            new \DateTimeImmutable('-3 days'),
        );
        $em->persist($expired1);

        $expired2 = new RefreshToken(
            $user2,
            'expired-user2',
            new \DateTimeImmutable('-1 day'),
        );
        $em->persist($expired2);
        $em->flush();

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Purged 2 expired refresh token', $tester->getDisplay());

        /** @var RefreshTokenRepository $repo */
        $repo = self::getContainer()->get(RefreshTokenRepository::class);
        $this->assertCount(0, $repo->findAll());
    }
}
