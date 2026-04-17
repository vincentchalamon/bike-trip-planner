<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CreateUserCommandTest extends ApiTestCase
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
        $command = $application->find('app:create-user');

        return new CommandTester($command);
    }

    #[Test]
    public function createsUserSuccessfully(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'newuser@example.com']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('newuser@example.com', $tester->getDisplay());

        /** @var UserRepository $userRepo */
        $userRepo = self::getContainer()->get(UserRepository::class);
        $user = $userRepo->findByEmail('newuser@example.com');
        $this->assertInstanceOf(User::class, $user);
    }

    #[Test]
    public function createsUserWithSpecificLocale(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'english@example.com', '--locale' => 'en']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        /** @var UserRepository $userRepo */
        $userRepo = self::getContainer()->get(UserRepository::class);
        $user = $userRepo->findByEmail('english@example.com');
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('en', $user->getLocale());
    }

    #[Test]
    public function createsUserWithDefaultFrenchLocale(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'french@example.com']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        /** @var UserRepository $userRepo */
        $userRepo = self::getContainer()->get(UserRepository::class);
        $user = $userRepo->findByEmail('french@example.com');
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('fr', $user->getLocale());
    }

    #[Test]
    public function createsMagicLinkForNewUser(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'magiclink@example.com']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        /** @var MagicLinkRepository $magicLinkRepo */
        $magicLinkRepo = self::getContainer()->get(MagicLinkRepository::class);
        $magicLinks = $magicLinkRepo->findAll();
        $this->assertNotEmpty($magicLinks, 'A magic link should be created for the new user');
    }

    #[Test]
    public function duplicateEmailReturnsFailure(): void
    {
        $em = $this->getEntityManager();
        $user = new User('existing@example.com');
        $em->persist($user);
        $em->flush();

        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'existing@example.com']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    #[Test]
    public function invalidEmailReturnsFailure(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'not-an-email']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid email', $tester->getDisplay());
    }

    #[Test]
    public function emptyEmailReturnsFailure(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['email' => '']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function invalidLocaleReturnsFailure(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'locale@example.com', '--locale' => 'de']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unsupported locale', $tester->getDisplay());
    }

    #[Test]
    public function createUserDeletesExistingAccessRequest(): void
    {
        $em = $this->getEntityManager();
        $accessRequest = new AccessRequest('earlyaccess@example.com', '127.0.0.1');
        $accessRequest->verify();

        $em->persist($accessRequest);
        $em->flush();

        $tester = $this->createCommandTester();
        $tester->execute(['email' => 'earlyaccess@example.com']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em->clear();
        $deleted = $em->getRepository(AccessRequest::class)->findOneBy(['email' => 'earlyaccess@example.com']);
        $this->assertNull($deleted, 'AccessRequest should be deleted after user creation');
    }
}
