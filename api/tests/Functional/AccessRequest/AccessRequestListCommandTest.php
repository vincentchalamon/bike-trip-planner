<?php

declare(strict_types=1);

namespace App\Tests\Functional\AccessRequest;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\AccessRequest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AccessRequestListCommandTest extends ApiTestCase
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
        $command = $application->find('app:access-request:list');

        return new CommandTester($command);
    }

    /**
     * @param non-empty-string $email
     */
    private function createVerifiedRequest(string $email, string $ip = '127.0.0.1'): AccessRequest
    {
        $em = $this->getEntityManager();
        $request = new AccessRequest($email, $ip);
        $request->verify();

        $em->persist($request);
        $em->flush();

        return $request;
    }

    #[Test]
    public function listWithNoVerifiedRequestsShowsInfoMessage(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No verified access requests found', $tester->getDisplay());
    }

    #[Test]
    public function listShowsVerifiedRequests(): void
    {
        $this->createVerifiedRequest('alice@example.com');
        $this->createVerifiedRequest('bob@example.com');

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('alice@example.com', $display);
        $this->assertStringContainsString('bob@example.com', $display);
    }

    #[Test]
    public function listFiltersWithEmailPattern(): void
    {
        $this->createVerifiedRequest('alice@example.com');
        $this->createVerifiedRequest('bob@other.com');

        $tester = $this->createCommandTester();
        $tester->execute(['--email' => 'alice']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('alice@example.com', $display);
        $this->assertStringNotContainsString('bob@other.com', $display);
    }

    #[Test]
    public function listReturnsFailureForInvalidBeforeDate(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--before' => 'not-a-date']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function listReturnsFailureForInvalidAfterDate(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--after' => 'not-a-date']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function listFiltersWithBeforeDate(): void
    {
        $this->createVerifiedRequest('filter-before-1@example.com');
        $this->createVerifiedRequest('filter-before-2@example.com');

        $tester = $this->createCommandTester();
        $tester->execute(['--before' => new \DateTimeImmutable('-1 day')->format(\DateTimeInterface::ATOM)]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('filter-before-1@example.com', $display);
        $this->assertStringNotContainsString('filter-before-2@example.com', $display);
    }

    #[Test]
    public function listFiltersWithAfterDate(): void
    {
        $this->createVerifiedRequest('filter-after-1@example.com');
        $this->createVerifiedRequest('filter-after-2@example.com');

        $tester = $this->createCommandTester();
        $tester->execute(['--after' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM)]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('filter-after-1@example.com', $display);
        $this->assertStringNotContainsString('filter-after-2@example.com', $display);
    }

    #[Test]
    public function listWithPaginationOptions(): void
    {
        $this->createVerifiedRequest('page@example.com');

        $tester = $this->createCommandTester();
        $tester->execute(['--page' => '1', '--limit' => '5']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
