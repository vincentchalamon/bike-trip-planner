<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuthRequestLinkTest extends ApiTestCase
{
    use Factories;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    #[Test]
    public function requestLinkWithExistingUserReturns202(): void
    {
        $em = $this->getEntityManager();
        $user = new User('alice@example.com');
        $em->persist($user);
        $em->flush();

        $response = self::createClient()->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'alice@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('message', $data);
        $this->assertNotEmpty($data['message']);
    }

    #[Test]
    public function requestLinkWithExistingUserReturnsNeutralMessage(): void
    {
        $em = $this->getEntityManager();
        $user = new User('bob@example.com');
        $em->persist($user);
        $em->flush();

        $response = self::createClient()->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'bob@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('message', $data);
        $this->assertNotEmpty($data['message']);
    }

    #[Test]
    public function requestLinkWithUnknownEmailReturnsSameResponse(): void
    {
        $response = self::createClient()->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'unknown@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('message', $data);
        $this->assertNotEmpty($data['message']);
    }

    #[Test]
    public function requestLinkWithEmptyEmailReturns422(): void
    {
        self::createClient()->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function requestLinkWithInvalidEmailFormatReturns422(): void
    {
        self::createClient()->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'not-an-email'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function requestLinkWithMissingEmailReturns422(): void
    {
        self::createClient()->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => new \stdClass(),
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function antiEnumerationResponseIsIdenticalForKnownAndUnknownEmails(): void
    {
        $em = $this->getEntityManager();
        $user = new User('known@example.com');
        $em->persist($user);
        $em->flush();

        $client = self::createClient();

        $knownResponse = $client->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'known@example.com'],
        ]);
        $knownData = $knownResponse->toArray(false);

        $unknownResponse = $client->request('POST', '/auth/request-link', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'ghost@example.com'],
        ]);
        $unknownData = $unknownResponse->toArray(false);

        $this->assertSame(202, $knownResponse->getStatusCode());
        $this->assertSame(202, $unknownResponse->getStatusCode());
        $this->assertSame($knownData['message'], $unknownData['message']);
    }
}
